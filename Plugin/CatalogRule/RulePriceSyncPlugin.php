<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\CatalogRule;

use Magento\CatalogRule\Model\Indexer\IndexBuilder;
use ParkkTech\FastMagento\Model\OpenSearch\CatalogRuleSyncer;

/**
 * After Magento (re)builds catalog-rule prices, push the fresh prices into the OpenSearch index
 * so the OS-served cart is always correct without a manual reindex.
 *
 * Covers every recalc path: a single product (reindexById), a set (reindexByIds), and the full
 * apply-all / rule-save rebuild (reindexFull). Best-effort — the syncer swallows and logs its own
 * errors, so a sync problem can never break the catalog-rule reindex itself.
 */
class RulePriceSyncPlugin
{
    public function __construct(private readonly CatalogRuleSyncer $catalogRuleSyncer)
    {
    }

    /**
     * @param mixed $result
     * @param int   $id
     * @return mixed
     */
    public function afterReindexById(IndexBuilder $subject, $result, $id)
    {
        $this->catalogRuleSyncer->syncByProductIds([(int) $id]);
        return $result;
    }

    /**
     * @param mixed $result
     * @param int[] $ids
     * @return mixed
     */
    public function afterReindexByIds(IndexBuilder $subject, $result, array $ids)
    {
        $this->catalogRuleSyncer->syncByProductIds($ids);
        return $result;
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public function afterReindexFull(IndexBuilder $subject, $result)
    {
        $this->catalogRuleSyncer->syncAll();
        return $result;
    }
}
