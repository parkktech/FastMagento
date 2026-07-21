<?php

namespace ParkkTech\FastMagento\Model\OpenSearch;

use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;

/**
 * Resolves a child product's parent ids from OpenSearch (the indexed `parent_ids` field),
 * with a per-request cache.
 *
 * Listing widgets (e.g. product sliders) call getParentIdsByChild() once per product — and
 * separately for each parent-capable type (configurable, grouped) — to decide whether to
 * show a child's parent instead. Natively each call is a catalog_product_super_link /
 * catalog_product_link query, so a slider over N products fires 2N such queries. This
 * resolver answers from OpenSearch instead, and the shared cache means one OS fetch per
 * child regardless of how many types ask.
 *
 * A found doc is authoritative (empty parent_ids = no parent). Returns null only when the
 * child isn't in OpenSearch at all, so callers can fall back to the native lookup.
 */
class ParentIdResolver
{
    /** @var array<int, int[]|null> */
    private array $cache = [];

    public function __construct(
        private readonly OpenSearchPdpFetcher $openSearchPdpFetcher
    ) {
    }

    /**
     * @param int $childId
     * @return int[]|null  parent ids from OS, or null if the child is not in OpenSearch
     */
    public function getOsParentIds(int $childId): ?array
    {
        // Empty/invalid id (freshly instantiated product objects, stale references):
        // nothing to resolve and nothing to warm — a product with no id has no parent.
        if ($childId <= 0) {
            return [];
        }
        if (array_key_exists($childId, $this->cache)) {
            return $this->cache[$childId];
        }

        $result = null;
        try {
            $doc = $this->openSearchPdpFetcher->fetchPdpById($childId);
            if (is_array($doc)) {
                $result = [];
                foreach ($doc['parent_ids'] ?? [] as $pid) {
                    $result[] = (int)$pid;
                }
            }
        } catch (\Throwable $e) {
            $result = null;
        }

        return $this->cache[$childId] = $result;
    }
}
