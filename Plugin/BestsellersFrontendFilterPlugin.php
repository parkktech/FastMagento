<?php

namespace ParkkTech\FastMagento\Plugin;

use Magento\Sales\Model\ResourceModel\Report\Bestsellers\Collection;
use ParkkTech\FastMagento\Model\OpenSearch\ParentIdResolver;
use ParkkTech\FastMagento\Helper\WriteLog;

/**
 * Drop dead products from the bestsellers report ON THE FRONTEND only.
 *
 * The bestsellers report aggregates from order history (sales_order_item), so it correctly
 * and permanently retains products that once sold but have since been deleted, disabled, or
 * hidden — that history must stay for backend reporting. But a storefront bestseller widget
 * must never surface them: a shopper can't buy a deleted product, and each dead entry also
 * steals a slot from a real best seller (top-20 with 8 dead = only 12 shown).
 *
 * This plugin is registered in etc/frontend/di.xml, so it runs on the frontend ONLY — the
 * admin Reports > Bestsellers grid keeps every row. It removes report rows whose product is
 * not present in OpenSearch, which is exactly the set of frontend-eligible products (the
 * indexer only indexes enabled + visible ones). Reusing ParentIdResolver also primes its
 * cache, so the parent-id lookups the slider does next cost no extra OpenSearch calls.
 */
class BestsellersFrontendFilterPlugin
{
    public function __construct(
        private readonly ParentIdResolver $parentIdResolver,
        private readonly WriteLog $writeLog
    ) {
    }

    /**
     * @param Collection $subject
     * @param Collection $result
     * @return Collection
     */
    public function afterLoad(Collection $subject, $result)
    {
        // Re-entry guard: this report collection re-enters load() from getItems(), so
        // without the flag the filter recurses infinitely.
        if ($subject->getFlag('fm_bestsellers_filtered')) {
            return $result;
        }
        $subject->setFlag('fm_bestsellers_filtered', true);

        try {
            $removeKeys = [];
            foreach ($subject->getItems() as $key => $item) {
                $productId = (int)$item->getData('product_id');
                // null = not in OpenSearch = deleted/disabled/not-visible => not shoppable.
                if ($productId <= 0 || $this->parentIdResolver->getOsParentIds($productId) === null) {
                    $removeKeys[] = $key;
                }
            }
            foreach ($removeKeys as $key) {
                $subject->removeItemByKey($key);
            }
        } catch (\Throwable $e) {
            // Never let filtering break the widget — leave the collection as loaded.
            $this->writeLog->writeErrorLog('Bestsellers frontend filter failed: ' . $e->getMessage());
        }
        return $result;
    }
}
