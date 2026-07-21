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
    /**
     * Product ids awaiting reprojection, deduped, flushed once after the response.
     * @var array<int,int>
     */
    private array $pendingReindexIds = [];

    /**
     * Product ids awaiting deletion from the index, flushed with the reprojection.
     * @var array<int,int>
     */
    private array $pendingDeleteIds = [];

    private bool $flushScheduled = false;

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
     * Defer + dedupe reprojection off the request's critical path.
     *
     * A single storefront request can trigger many stock-item saves for the SAME products —
     * e.g. a 3rd-party quantity-validator observer saves each quote item's stock on cart load,
     * which cascades through MSI's legacy→source-item sync into this syncer. Reprojecting a
     * large configurable (hundreds of children) inline, per save, is what makes the cart and
     * checkout crawl. Instead we accumulate the affected ids and flush ONE reprojection after
     * the response is sent to the client (fastcgi_finish_request under php-fpm), so the shopper
     * never waits on it while the index still ends up current within the same request.
     */
    private function scheduleFlush(): void
    {
        if ($this->flushScheduled) {
            return;
        }
        $this->flushScheduled = true;
        register_shutdown_function([$this, 'flushDeferredSync']);
    }

    /**
     * Runs at request shutdown: release the response first, then reproject the deduped set.
     * Public only so register_shutdown_function can reach it; not part of the API.
     */
    public function flushDeferredSync(): void
    {
        // Send the buffered response to the client before doing the heavy index work.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $deleteIds = array_values($this->pendingDeleteIds);
        $reindexIds = array_values($this->pendingReindexIds);
        $this->pendingDeleteIds = [];
        $this->pendingReindexIds = [];
        $this->flushScheduled = false;

        if ($deleteIds) {
            try {
                $client = $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
                foreach ($deleteIds as $id) {
                    $client->getOpenSearchClient()->delete([
                        'index' => $this->openSearchConfig->getIndexName(),
                        'id' => (string) $id,
                        'client' => ['ignore' => [404]],
                    ]);
                }
            } catch (\Throwable $e) {
                $this->writeLog->writeErrorLog('[FastMagento] deferred delete sync failed: ' . $e->getMessage());
            }
        }

        if ($reindexIds) {
            try {
                $this->productIndexer->executeList($reindexIds);
            } catch (\Throwable $e) {
                $this->writeLog->writeErrorLog('[FastMagento] deferred stock sync failed: ' . $e->getMessage());
            }
        }
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
            $this->pendingDeleteIds[$productId] = $productId;
            foreach ($this->getParentIds([$productId]) as $parentId) {
                $this->pendingReindexIds[$parentId] = $parentId;
            }
            $this->scheduleFlush();
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
            // Drop ids whose live stock already matches the index — a no-op save (e.g. a
            // 3rd-party quantity validator re-saving unchanged quote-item stock on every cart
            // view) must not schedule a reprojection. Fail-safe: anything uncertain is kept.
            $ids = $this->filterStockChanged($ids);
            if (!$ids) {
                return;
            }
            $ids = array_values(array_unique(array_merge($ids, $this->getParentIds($ids))));
            foreach ($ids as $id) {
                $this->pendingReindexIds[$id] = $id;
            }
            $this->scheduleFlush();
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] realtime stock sync (ids) failed: ' . $e->getMessage());
        }
    }

    /**
     * Return only the ids whose live stock (qty / in-stock) differs from what OpenSearch
     * already has indexed. Conservative: if a product isn't indexed, has no stock row, or
     * anything errors, it is KEPT (reprojected) — we never risk skipping a real stock change.
     *
     * @param int[] $ids
     * @return int[]
     */
    private function filterStockChanged(array $ids): array
    {
        try {
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from(
                        $this->resource->getTableName('cataloginventory_stock_item'),
                        ['product_id', 'qty', 'is_in_stock']
                    )
                    ->where('product_id IN (?)', $ids)
                    ->where('stock_id = ?', 1)
            );
            $live = [];
            foreach ($rows as $row) {
                $live[(int) $row['product_id']] = [
                    'qty' => (float) $row['qty'],
                    'is_in_stock' => (int) $row['is_in_stock'],
                ];
            }

            $indexed = $this->getIndexedStock($ids);

            $changed = [];
            foreach ($ids as $id) {
                // No live stock row or not indexed → can't prove it's unchanged → reproject.
                if (!isset($live[$id]) || !isset($indexed[$id])) {
                    $changed[] = $id;
                    continue;
                }
                if ($live[$id]['is_in_stock'] !== $indexed[$id]['is_in_stock']
                    || abs($live[$id]['qty'] - $indexed[$id]['qty']) > 0.0001
                ) {
                    $changed[] = $id;
                }
            }
            return $changed;
        } catch (\Throwable $e) {
            // On any failure, don't risk staleness — reproject everything we were given.
            return $ids;
        }
    }

    /**
     * Batch-read indexed stock (is_in_stock + stock_data.qty) for a set of product ids.
     *
     * @param int[] $ids
     * @return array<int, array{qty:float,is_in_stock:int}>
     */
    private function getIndexedStock(array $ids): array
    {
        $out = [];
        try {
            $client = $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
            $response = $client->getOpenSearchClient()->mget([
                'index' => $this->openSearchConfig->getIndexName(),
                'body' => ['ids' => array_map('strval', $ids)],
            ]);
            foreach (($response['docs'] ?? []) as $doc) {
                if (empty($doc['found']) || !isset($doc['_source'])) {
                    continue;
                }
                $src = $doc['_source'];
                $out[(int) $doc['_id']] = [
                    'qty' => (float) ($src['stock_data']['qty'] ?? 0.0),
                    'is_in_stock' => (int) (bool) ($src['is_in_stock'] ?? false),
                ];
            }
        } catch (\Throwable $e) {
            // Return what we have; callers treat missing ids as "changed" (reproject).
            $this->writeLog->writeErrorLog('[FastMagento] indexed-stock read failed: ' . $e->getMessage());
        }
        return $out;
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
