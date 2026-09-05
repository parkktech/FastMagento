<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Indexer;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
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

    /**
     * Every OTHER category attribute the storefront reads while rendering a category page.
     *
     * Derived by instrumenting Category::getData() across category renders rather than by reading
     * the schema: this is the set the storefront actually asks for, which is what a hydrated
     * category has to be able to answer without going to the database. Design and layout
     * attributes are in here deliberately -- they are usually empty, and it is precisely a
     * category where they are NOT empty that would render wrongly if they were left out.
     */
    private const STOREFRONT_ATTRIBUTES = [
        'description',
        'meta_title', 'meta_keywords', 'meta_description',
        'image',
        'page_layout', 'custom_layout_update', 'custom_layout_update_file',
        'custom_design', 'custom_design_from', 'custom_design_to', 'custom_apply_to_products',
        'custom_use_parent_settings',
        'available_sort_by', 'default_sort_by',
        'filter_price_range',
        'landing_page',
        // Complete the set: with these two, STOREFRONT_ATTRIBUTES + ATTRIBUTES covers EVERY
        // non-static category attribute in a stock install, which is what lets the read handler
        // be answered from the index rather than merely supplemented by it.
        'children', 'path_in_store',
    ];

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly WriteLog $writeLog,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly \ParkkTech\FastMagento\Model\OpenSearch\IndexSettings $indexSettings
    ) {
    }

    /** Cached category url suffix for the index store (e.g. ".html"). */
    private ?string $categoryUrlSuffix = null;

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
     * @param int[]|null $ids Empty/null all categories.
     */
    public function execute($ids = []): void
    {
        try {
            $storeId = $this->getIndexStoreId();
            $indexName = $this->getIndexName();
            $client = $this->getSearchClient();

            $collection = $this->categoryCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect(array_merge(self::ATTRIBUTES, self::STOREFRONT_ATTRIBUTES));
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

    /**
     * The storefront attribute values for one category, as a flat map.
     *
     * Values are taken with getData() rather than typed getters so what lands in the index is the
     * raw attribute value the model itself would hand back — the hydrator's job is to reproduce
     * the loaded model exactly, and a cast here would be a difference it could not undo.
     *
     * @param mixed $category
     * @return array<string, mixed>
     */
    private function buildStorefrontAttrs($category): array
    {
        $attrs = [];
        foreach (self::STOREFRONT_ATTRIBUTES as $code) {
            $value = $category->getData($code);
            $attrs[$code] = $value === '' ? null : $value;
        }

        return $attrs;
    }

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
            'url_path' => $this->resolveUrlPath($category, $requestPaths[$entityId] ?? '', $storeId),
            'all_children' => (string) ($category->getData('all_children') ?? ''),
            'request_path' => $requestPaths[$entityId] ?? '',
            'store_id' => $storeId,
            'attribute_set_id' => (int) $category->getAttributeSetId(),
            // The remaining storefront attributes, kept under one dynamic object so adding an
            // attribute to STOREFRONT_ATTRIBUTES needs no mapping change. Null is recorded as
            // null rather than dropped: "this category has no custom_design" and "we did not
            // index custom_design" have to stay distinguishable, because the hydrator refuses
            // to build a category from a document that cannot answer for every attribute.
            'fm_attrs' => $this->buildStorefrontAttrs($category),
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
     * Category url_path, falling back to the canonical url_rewrite request_path (suffix stripped)
     * when the legacy url_path attribute was never generated. That attribute is null for many
     * categories here (~half), yet every category has a url_rewrite record, so headless clients
     * that build hrefs from url_path (e.g. Daffodil) would otherwise get "/null.html" or an empty
     * path. Native Luma is unaffected either way (it routes off request_path).
     *
     * @param \Magento\Catalog\Model\Category $category
     */
    private function resolveUrlPath($category, string $requestPath, int $storeId): string
    {
        $urlPath = (string) ($category->getUrlPath() ?? '');
        if ($urlPath !== '' || $requestPath === '') {
            return $urlPath;
        }
        $suffix = $this->getCategoryUrlSuffix($storeId);
        if ($suffix !== '' && str_ends_with($requestPath, $suffix)) {
            return substr($requestPath, 0, -strlen($suffix));
        }
        return $requestPath;
    }

    /**
     * Configured category url suffix (default ".html") for the index store, read once.
     */
    private function getCategoryUrlSuffix(int $storeId): string
    {
        if ($this->categoryUrlSuffix === null) {
            $this->categoryUrlSuffix = (string) ($this->scopeConfig->getValue(
                'catalog/seo/category_url_suffix',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ) ?? '');
        }
        return $this->categoryUrlSuffix;
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
    /**
     * Make everything written so far immediately searchable.
     *
     * OpenSearch writes are durable at once but only become visible to SEARCH at the next refresh
     * — one second by default. CategoryDataProvider reads the tree with a search, so without this
     * the FIRST storefront request after an admin category save still renders the previous
     * document while a realtime GET on the same id already returns the new one. Measured exactly
     * that: request one stale, request two correct.
     *
     * Called only from the instant single-category path, never from a full reindex, where forcing
     * a refresh per batch would work against the bulk write.
     */
    public function refreshIndex(): void
    {
        try {
            $this->getSearchClient()->getOpenSearchClient()->indices()->refresh(
                ['index' => $this->getIndexName()]
            );
        } catch (\Throwable $e) {
            // A missed refresh only costs a second of staleness; never surface it.
            $this->writeLog->writeErrorLog(
                '[FastMagento] category index refresh failed: ' . $e->getMessage()
            );
        }
    }

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
                // Per-item rejections return HTTP 200 with errors:true. Previously logged and
                // swallowed, which let a partial category tree pass as a successful reindex.
                $failed = 0;
                $reasons = [];
                foreach ($response['items'] ?? [] as $item) {
                    $action = reset($item);
                    if (is_array($action) && isset($action['error'])) {
                        $failed++;
                        $reasons[(string) ($action['error']['reason'] ?? 'unknown error')] = true;
                    }
                }
                $message = (string) __(
                    'OpenSearch rejected %1 category document(s): %2',
                    $failed,
                    implode('; ', array_slice(array_keys($reasons), 0, 3))
                );
                $this->writeLog->writeErrorLog('[FastMagento] ' . $message);
                throw new LocalizedException(__($message));
            }
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new LocalizedException(__('Category bulk NDJSON error: %1', $e->getMessage()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMapping(): array
    {
        return $this->indexSettings->applyTo([
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
                    'attribute_set_id' => ['type' => 'integer'],
                    // Not indexed for search — read back from _source only.
                    'fm_attrs' => ['type' => 'object', 'enabled' => false],
                ],
            ],
        ]);
    }
}
