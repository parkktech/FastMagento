<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Indexer;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Helper\WriteLog;

/**
 * Projects the category tree into a dedicated OpenSearch index (magento2_categories) so the
 * storefront menu / breadcrumbs / layered-nav can render category data without hitting the
 * catalog_category_entity* EAV tables. Mirrors ProductIndexer's client/bulk patterns.
 *
 * The category tree is tiny (hundreds of rows), so the whole set is read in ONE collection
 * query (all needed attributes selected up front — no per-category EAV fan-out) plus one
 * url_rewrite query for request paths, then streamed to OpenSearch in FLUSH_SIZE chunks.
 *
 * Doc shape carries everything the read paths need: tree structure (parent/path/level/
 * children), menu flags (is_active/include_in_menu/is_anchor/display_mode), and the
 * store-scoped url_key/url_path/request_path. Values are projected for the default store
 * view (matches the single global product serving index).
 */
class CategoryIndexer implements ActionInterface, MviewActionInterface
{
    private const FLUSH_SIZE = 200;

    /** Category attributes pulled in the single collection load. */
    private const ATTRIBUTES = [
        'name', 'is_active', 'include_in_menu', 'is_anchor', 'display_mode', 'url_key', 'url_path',
        'all_children',
    ];

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly WriteLog $writeLog
    ) {
    }

    /**
     * @param int|string $id
     */
    public function executeRow($id): void
    {
        $this->execute([(int) $id]);
    }

    public function executeList(array $ids): void
    {
        $this->execute($ids);
    }

    /**
     * Full rebuild: drop + recreate the index, then project every category.
     */
    public function executeFull(): void
    {
        $indexName = $this->getIndexName();
        $client = $this->getSearchClient();
        try {
            if ($client->indexExists($indexName)) {
                $client->deleteIndex($indexName);
            }
            $client->createIndex($indexName, $this->buildMapping());
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] category index (re)create failed: ' . $e->getMessage());
        }
        $this->execute([]);
    }

    /**
     * @param int[]|null $ids Empty/null → all categories.
     */
    public function execute($ids = []): void
    {
        try {
            $storeId = $this->getIndexStoreId();
            $indexName = $this->getIndexName();
            $client = $this->getSearchClient();

            $collection = $this->categoryCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect(self::ATTRIBUTES);
            if (!empty($ids)) {
                $collection->addFieldToFilter('entity_id', ['in' => array_map('intval', $ids)]);
            }

            $requestPaths = $this->loadRequestPaths($storeId, empty($ids) ? null : array_map('intval', $ids));

            $docs = [];
            foreach ($collection as $category) {
                $docs[] = [
                    'id' => (string) $category->getId(),
                    'body' => $this->buildDoc($category, $storeId, $requestPaths),
                ];
                if (count($docs) >= self::FLUSH_SIZE) {
                    $this->bulkIndexNDJSON($client, $indexName, $docs);
                    $docs = [];
                }
            }
            if ($docs) {
                $this->bulkIndexNDJSON($client, $indexName, $docs);
            }
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] category execute error: ' . $e->getMessage());
        }
    }

    /**
     * @param \Magento\Catalog\Model\Category $category
     * @param array<int, string> $requestPaths
     * @return array<string, mixed>
     */
    private function buildDoc($category, int $storeId, array $requestPaths): array
    {
        $entityId = (int) $category->getId();
        $path = (string) $category->getPath();
        $pathIds = array_values(array_filter(array_map('intval', explode('/', $path))));
        $parentId = (int) $category->getParentId();

        return [
            'entity_id' => $entityId,
            'parent_id' => $parentId,
            'path' => $path,
            'path_ids' => $pathIds,
            'level' => (int) $category->getLevel(),
            'position' => (int) $category->getPosition(),
            'children_count' => (int) $category->getChildrenCount(),
            'name' => (string) $category->getName(),
            'is_active' => (int) ($category->getIsActive() ?? 0),
            'include_in_menu' => (int) ($category->getIncludeInMenu() ?? 0),
            'is_anchor' => (int) ($category->getIsAnchor() ?? 0),
            'display_mode' => (string) ($category->getDisplayMode() ?? ''),
            'url_key' => (string) ($category->getUrlKey() ?? ''),
            'url_path' => (string) ($category->getUrlPath() ?? ''),
            'all_children' => (string) ($category->getData('all_children') ?? ''),
            'request_path' => $requestPaths[$entityId] ?? '',
            'store_id' => $storeId,
        ];
    }

    /**
     * One query for the canonical category request paths (redirect_type=0) in this store.
     *
     * @param int[]|null $ids
     * @return array<int, string> entity_id => request_path
     */
    private function loadRequestPaths(int $storeId, ?array $ids): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('url_rewrite'), ['entity_id', 'request_path'])
            ->where('entity_type = ?', 'category')
            ->where('store_id = ?', $storeId)
            ->where('redirect_type = ?', 0);
        if ($ids !== null && $ids) {
            $select->where('entity_id IN (?)', $ids);
        }
        // entity_id is unique per (store, redirect_type=0); fetchPairs keeps the canonical path.
        return array_map('strval', $connection->fetchPairs($select));
    }

    /**
     * Default store view whose values are projected into the serving index (matches the
     * product index strategy: one global serving doc per category).
     */
    private function getIndexStoreId(): int
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();
            if ($store && (int) $store->getId() > 0) {
                return (int) $store->getId();
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return 1;
    }

    private function getSearchClient()
    {
        return $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
    }

    public function getIndexName(): string
    {
        return $this->openSearchConfig->getCategoryIndexName();
    }

    /**
     * @param mixed $client
     * @param array<int, array{id:string, body:array}> $docs
     * @throws LocalizedException
     */
    private function bulkIndexNDJSON($client, string $indexName, array $docs): void
    {
        if (!$docs) {
            return;
        }
        $lines = '';
        foreach ($docs as $doc) {
            $lines .= json_encode(['index' => ['_id' => $doc['id'], '_index' => $indexName]]) . "\n";
            $lines .= json_encode($doc['body']) . "\n";
        }
        $lines .= "\n";
        try {
            $response = $client->getOpenSearchClient()->bulk(['body' => $lines]);
            if (isset($response['errors']) && $response['errors']) {
                $this->writeLog->writeErrorLog('[FastMagento] category bulk errors: ' . json_encode($response));
            }
        } catch (\Exception $e) {
            throw new LocalizedException(__('Category bulk NDJSON error: %1', $e->getMessage()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMapping(): array
    {
        return [
            'settings' => [
                'analysis' => ['analyzer' => ['default' => ['type' => 'standard']]],
            ],
            'mappings' => [
                'dynamic' => false,
                'properties' => [
                    'entity_id' => ['type' => 'integer'],
                    'parent_id' => ['type' => 'integer'],
                    'path' => ['type' => 'keyword'],
                    'path_ids' => ['type' => 'integer'],
                    'level' => ['type' => 'integer'],
                    'position' => ['type' => 'integer'],
                    'children_count' => ['type' => 'integer'],
                    'name' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]]],
                    'is_active' => ['type' => 'integer'],
                    'include_in_menu' => ['type' => 'integer'],
                    'is_anchor' => ['type' => 'integer'],
                    'display_mode' => ['type' => 'keyword'],
                    'url_key' => ['type' => 'keyword'],
                    'url_path' => ['type' => 'keyword'],
                    'all_children' => ['type' => 'keyword'],
                    'request_path' => ['type' => 'keyword'],
                    'store_id' => ['type' => 'integer'],
                ],
            ],
        ];
    }
}
