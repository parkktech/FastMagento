<?php

namespace ParkkTech\FastMagento\Plugin;

use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

/**
 * Collapse the per-category url_rewrite N+1 into a single query per store.
 *
 * The storefront mega-menu / breadcrumbs / category tree call $category->getUrl() for
 * every category, and each call resolves through UrlFinderInterface::findOneByData() with
 * an {entity_type: category, entity_id, store_id, redirect_type} filter — one url_rewrite
 * row fetch per category. On the search/category pages this alone fired ~108 queries.
 *
 * On the first such lookup we run findAllByData() ONCE with the same filter minus
 * entity_id (one query returning every category rewrite for that store/redirect_type),
 * cache the resulting UrlRewrite objects keyed by entity_id, and serve every subsequent
 * per-category lookup from the map. Result set and object type are produced by core's own
 * findAllByData(), so callers see byte-identical UrlRewrite objects.
 *
 * Scope is limited to single-entity category lookups by id; anything else (request_path
 * lookups, product/cms entities, array entity_id) falls straight through to native.
 */
class CategoryUrlFinderPlugin
{
    /** @var array<string, array<int, UrlRewrite|null>> batched category rewrites keyed by filter, then entity_id */
    private array $categoryMap = [];

    /**
     * @param UrlFinderInterface $subject
     * @param callable $proceed
     * @param array $data
     * @return UrlRewrite|null
     */
    public function aroundFindOneByData(UrlFinderInterface $subject, callable $proceed, array $data)
    {
        // Only optimize the single-category-by-id request_path lookup that drives the N+1.
        if (($data[UrlRewrite::ENTITY_TYPE] ?? null) !== 'category'
            || !isset($data[UrlRewrite::ENTITY_ID], $data[UrlRewrite::STORE_ID])
            || is_array($data[UrlRewrite::ENTITY_ID])
            || array_key_exists(UrlRewrite::REQUEST_PATH, $data)
        ) {
            return $proceed($data);
        }

        try {
            $entityId = (int) $data[UrlRewrite::ENTITY_ID];

            $batchFilter = $data;
            unset($batchFilter[UrlRewrite::ENTITY_ID]);
            $cacheKey = $this->cacheKey($batchFilter);

            if (!isset($this->categoryMap[$cacheKey])) {
                $map = [];
                foreach ($subject->findAllByData($batchFilter) as $rewrite) {
                    // First row wins, mirroring core doFindOneByData()'s fetchRow LIMIT 1.
                    $map[(int) $rewrite->getEntityId()] ??= $rewrite;
                }
                $this->categoryMap[$cacheKey] = $map;
            }

            return $this->categoryMap[$cacheKey][$entityId] ?? null;
        } catch (\Throwable $e) {
            return $proceed($data);
        }
    }

    /**
     * @param array $filter
     * @return string
     */
    private function cacheKey(array $filter): string
    {
        ksort($filter);
        return implode('|', array_map(
            static fn ($k, $v) => $k . '=' . (is_array($v) ? implode(',', $v) : (string) $v),
            array_keys($filter),
            $filter
        ));
    }
}
