<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\OpenSearch;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Search\EngineResolverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagento\Model\Indexer\ProductIndexer;

/**
 * Keeps the OpenSearch product index live with inventory in real time.
 *
 * MSI mutates stock through SKU-keyed tables (inventory_source_item / reservations) that a
 * product-entity mview subscription can't map, so order placement, refunds and the
 * inventory API would otherwise leave OpenSearch stale until a full reindex. The order /
 * credit-memo observers and the SourceItemsSave/Delete plugins funnel the affected products
 * here, and we reproject just those docs (plus any configurable/grouped/bundle parent, so
 * child_products[].is_in_stock and the swatch allow-list stay correct) straight away.
 *
 * Every entry point is best-effort: a sync failure is logged, never surfaced, so it can't
 * break checkout, a refund or an inventory save.
 */
class StockSyncer
{
    public function __construct(
        private readonly ProductIndexer $productIndexer,
        private readonly ResourceConnection $resource,
        private readonly WriteLog $writeLog,
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $openSearchConfig
    ) {
    }

    /**
     * Remove a deleted product's doc (and reproject its parents) so the index never serves
     * a stale product. Best-effort.
     */
    public function removeProduct(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }
        try {
            $parents = $this->getParentIds([$productId]);
            $client = $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
            $client->getOpenSearchClient()->delete([
                'index' => $this->openSearchConfig->getIndexName(),
                'id' => (string) $productId,
                'client' => ['ignore' => [404]],
            ]);
            if ($parents) {
                $this->productIndexer->executeList(array_values(array_unique($parents)));
            }
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] realtime delete sync failed: ' . $e->getMessage());
        }
    }

    /**
     * @param int[] $productIds
     */
    public function syncByProductIds(array $productIds): void
    {
        try {
            $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
            if (!$ids) {
                return;
            }
            $ids = array_values(array_unique(array_merge($ids, $this->getParentIds($ids))));
            $this->productIndexer->executeList($ids);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] realtime stock sync (ids) failed: ' . $e->getMessage());
        }
    }

    /**
     * @param string[] $skus
     */
    public function syncBySkus(array $skus): void
    {
        try {
            $skus = array_values(array_unique(array_filter(array_map('strval', $skus))));
            if (!$skus) {
                return;
            }
            $this->syncByProductIds($this->skusToIds($skus));
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] realtime stock sync (skus) failed: ' . $e->getMessage());
        }
    }

    /**
     * Direct DB parent lookup (configurable/grouped via super_link, all composites via
     * catalog_product_relation) — deliberately NOT the OpenSearch-served getParentIdsByChild,
     * which is overridden to return [] for indexed children.
     *
     * @param int[] $childIds
     * @return int[]
     */
    private function getParentIds(array $childIds): array
    {
        $connection = $this->resource->getConnection();
        $parents = [];

        $superLink = $connection->select()
            ->from($this->resource->getTableName('catalog_product_super_link'), ['parent_id'])
            ->where('product_id IN (?)', $childIds);
        $parents[] = $connection->fetchCol($superLink);

        $relation = $connection->select()
            ->from($this->resource->getTableName('catalog_product_relation'), ['parent_id'])
            ->where('child_id IN (?)', $childIds);
        $parents[] = $connection->fetchCol($relation);

        return array_map('intval', array_merge(...$parents));
    }

    /**
     * @param string[] $skus
     * @return int[]
     */
    private function skusToIds(array $skus): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id'])
            ->where('sku IN (?)', $skus);

        return array_map('intval', $connection->fetchCol($select));
    }
}
