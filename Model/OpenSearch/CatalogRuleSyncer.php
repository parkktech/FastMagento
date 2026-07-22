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
 * Keeps the OpenSearch index's catalog-rule prices live so the OS-served cart never needs a
 * manual reindex to be correct.
 *
 * Product edits reproject via ProductRepositoryPlugin and stock via StockSyncer, but a catalog
 * RULE recalculation only rewrites the native `catalogrule_product_price` table — nothing was
 * reprojecting the OS docs' `catalog_rule_prices` map. So after any rule change the index drifted
 * (guest/wholesale prices stale) until a full reindex reconciled it. RulePriceSyncPlugin funnels
 * every rule-recalc path (Magento\CatalogRule\Model\Indexer\IndexBuilder::reindexById / ByIds /
 * Full) here, and we patch ONLY the rule-price fields of the affected docs straight away.
 *
 * Faithful to ProductIndexer::prepareDoc: `catalog_rule_prices` is the {group_id => rule_price}
 * map from `catalogrule_product_price` (today, website 1) and `catalog_rule_price` the group-0
 * scalar — for the product AND, on a composite parent, each child_products[] entry. No stock,
 * price, attributes or relations are touched.
 *
 * Every entry point is best-effort: a failure is logged, never surfaced, so it can't break a
 * catalog-rule reindex. Any doc missing/partial in the index (or a bulk error) falls back to a
 * full reprojection, so the index can't be left stale.
 */
class CatalogRuleSyncer
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
     * Sync the given products' rule prices (plus any composite parents, so their
     * child_products[] rule slice stays correct).
     *
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
            $this->patchAndReproject($ids);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] rule-price sync (ids) failed: ' . $e->getMessage());
        }
    }

    /**
     * Sync EVERY product that currently has a catalog-rule price (the reindexFull / nightly
     * apply-all path). Batched so a full rule recompute never loads the whole set at once.
     */
    public function syncAll(): void
    {
        try {
            $connection = $this->resource->getConnection();
            $ids = array_map('intval', $connection->fetchCol(
                $connection->select()
                    ->distinct()
                    ->from($this->resource->getTableName('catalogrule_product_price'), ['product_id'])
                    ->where('website_id = ?', 1)
                    ->where('rule_date = ?', date('Y-m-d'))
            ));
            if (!$ids) {
                return;
            }
            // Include composite parents whose children just got (re)priced.
            $ids = array_values(array_unique(array_merge($ids, $this->getParentIds($ids))));
            foreach (array_chunk($ids, 500) as $chunk) {
                $this->patchAndReproject($chunk);
            }
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] rule-price sync (all) failed: ' . $e->getMessage());
        }
    }

    /**
     * Patch the rule-price fields of the given docs; anything that can't be patched safely is
     * reprojected in full so the index is never left stale.
     *
     * @param int[] $ids
     */
    private function patchAndReproject(array $ids): void
    {
        $misses = $this->patchRulePriceDocs($ids);
        if ($misses) {
            try {
                $this->productIndexer->executeList($misses);
            } catch (\Throwable $e) {
                $this->writeLog->writeErrorLog('[FastMagento] rule-price reproject fallback failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * mget the docs, patch only the rule-price fields (top-level + composite children) from the
     * fresh catalogrule_product_price, bulk re-index. Returns ids that must be fully reprojected.
     *
     * @param int[] $ids
     * @return int[] misses (reproject fully)
     */
    private function patchRulePriceDocs(array $ids): array
    {
        try {
            $client = $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
            $indexName = $this->openSearchConfig->getIndexName();

            $response = $client->getOpenSearchClient()->mget([
                'index' => $indexName,
                'body' => ['ids' => array_map('strval', $ids)],
            ]);

            $sources = [];
            $misses = [];
            foreach (($response['docs'] ?? []) as $doc) {
                $id = (int) ($doc['_id'] ?? 0);
                if (!$id) {
                    continue;
                }
                if (empty($doc['found']) || !isset($doc['_source']) || !is_array($doc['_source'])) {
                    $misses[$id] = $id;
                    continue;
                }
                $sources[$id] = $doc['_source'];
            }
            foreach ($ids as $id) {
                if (!isset($sources[$id]) && !isset($misses[$id])) {
                    $misses[$id] = $id;
                }
            }
            if (!$sources) {
                return array_values($misses);
            }

            // ONE rule-price read for every product AND every referenced composite child.
            $ruleIds = array_keys($sources);
            foreach ($sources as $src) {
                foreach (($src['child_products'] ?? []) as $child) {
                    $cid = (int) ($child['entity_id'] ?? 0);
                    if ($cid) {
                        $ruleIds[] = $cid;
                    }
                }
            }
            $ruleMap = $this->getRulePriceMap(array_values(array_unique($ruleIds)));

            $docsToIndex = [];
            foreach ($sources as $id => $src) {
                $patched = $this->patchDocRules($id, $src, $ruleMap);
                if ($patched === null) {
                    $misses[$id] = $id;
                    continue;
                }
                $docsToIndex[] = ['id' => (string) $id, 'body' => $patched];
            }

            if ($docsToIndex && !$this->bulkIndex($client, $indexName, $docsToIndex)) {
                foreach ($docsToIndex as $doc) {
                    $misses[(int) $doc['id']] = (int) $doc['id'];
                }
            }

            return array_values($misses);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] rule-price patch failed; full reproject: ' . $e->getMessage());
            return $ids;
        }
    }

    /**
     * Apply the fresh rule prices to one doc _source. Returns the patched _source, or null if the
     * doc is too partial to patch safely (composite parent with no child_products) → reproject.
     *
     * @param array<string, mixed> $src
     * @param array<int, array<int, float>> $ruleMap product_id => [group_id => rule_price]
     * @return array<string, mixed>|null
     */
    private function patchDocRules(int $id, array $src, array $ruleMap): ?array
    {
        // Mirror ProductIndexer::prepareDoc exactly.
        $map = $ruleMap[$id] ?? [];
        $src['catalog_rule_prices'] = $map;
        $src['catalog_rule_price'] = isset($map[0]) ? ['rule_price' => (float) $map[0]] : [];

        $type = (string) ($src['type_id'] ?? '');
        if (in_array($type, ['configurable', 'grouped', 'bundle'], true)) {
            if (empty($src['child_products']) || !is_array($src['child_products'])) {
                return null;
            }
            foreach ($src['child_products'] as &$child) {
                $cid = (int) ($child['entity_id'] ?? 0);
                if (!$cid) {
                    return null;
                }
                $cmap = $ruleMap[$cid] ?? [];
                $child['catalog_rule_prices'] = $cmap;
                $child['catalog_rule_price'] = isset($cmap[0]) ? ['rule_price' => (float) $cmap[0]] : [];
            }
            unset($child);
        }

        return $src;
    }

    /**
     * {product_id => {group_id => rule_price}} — identical query to
     * ProductIndexer::getRulePriceMapByGroup so a patch matches a full reproject byte-for-byte.
     *
     * @param int[] $productIds
     * @return array<int, array<int, float>>
     */
    private function getRulePriceMap(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName('catalogrule_product_price'),
                    ['product_id', 'customer_group_id', 'rule_price']
                )
                ->where('product_id IN (?)', $productIds)
                ->where('website_id = ?', 1)
                ->where('rule_date = ?', date('Y-m-d'))
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['product_id']][(int) $row['customer_group_id']] = (float) $row['rule_price'];
        }
        return $map;
    }

    /**
     * Configurable/grouped via super_link + all composites via catalog_product_relation — a
     * child's rule change must refresh the parent's child_products[] slice too.
     *
     * @param int[] $childIds
     * @return int[]
     */
    private function getParentIds(array $childIds): array
    {
        $connection = $this->resource->getConnection();
        $parents = [];
        $parents[] = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_super_link'), ['parent_id'])
                ->where('product_id IN (?)', $childIds)
        );
        $parents[] = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_relation'), ['parent_id'])
                ->where('child_id IN (?)', $childIds)
        );
        return array_map('intval', array_merge(...$parents));
    }

    /**
     * @param array<int, array{id:string, body:array<string,mixed>}> $docs
     */
    private function bulkIndex($client, string $indexName, array $docs): bool
    {
        $lines = '';
        foreach ($docs as $doc) {
            $lines .= json_encode(['index' => ['_id' => $doc['id'], '_index' => $indexName]]) . "\n";
            $lines .= json_encode($doc['body']) . "\n";
        }
        $lines .= "\n";
        $response = $client->getOpenSearchClient()->bulk(['body' => $lines]);
        if (!empty($response['errors'])) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] rule-price patch bulk errors: ' . json_encode($response['items'] ?? [])
            );
            return false;
        }
        return true;
    }
}
