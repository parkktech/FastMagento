<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Quote;

use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;
use ParkkTech\FastMagento\Model\ResourceModel\Quote\Item\AbsentCheckProductCollection;

/**
 * Answer the cart's "do these products still exist?" lookup from OpenSearch.
 *
 * See AbsentCheckProductCollection for why the lookup is a collection subclass; the OpenSearch
 * dependency lives here so it is constructor-injected instead of resolved at call time.
 *
 * SAFETY: a document present in the index is proof the product exists (the indexer removes
 * documents on delete). The reverse is never assumed: any id the index cannot vouch for sends the
 * whole call to the native query, so a stale or partial index can never make a real product look
 * absent and drop it out of somebody's cart. One failure disables the lookup for the request.
 */
class AbsentCheckFromIndexPlugin
{
    private bool $unavailable = false;

    public function __construct(private readonly OpenSearchPdpFetcher $fetcher)
    {
    }

    /**
     * @param int|string|null $limit
     * @param int|string|null $offset
     * @return array
     */
    public function aroundGetAllIds(
        AbsentCheckProductCollection $subject,
        callable $proceed,
        $limit = null,
        $offset = null
    ) {
        $ids = $subject->getFmFilteredIds();
        if ($limit !== null || $offset !== null || !$ids || $this->unavailable) {
            return $proceed($limit, $offset);
        }
        try {
            $docs = $this->fetcher->fetchByIds($ids);
            foreach ($ids as $id) {
                if (!isset($docs[$id])) {
                    // The index cannot vouch for this id. Do not conclude the product is gone —
                    // ask the database, which is the only thing entitled to that answer.
                    return $proceed($limit, $offset);
                }
            }
            return $ids;
        } catch (\Throwable $e) {
            $this->unavailable = true;
            return $proceed($limit, $offset);
        }
    }
}
