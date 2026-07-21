<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ParkkTech\FastMagento\Model\OpenSearch\StockSyncer;

/**
 * On order placement, reproject the ordered products (and their configurable/grouped/bundle
 * parents) into OpenSearch so stock qty / in-stock status stay live with the reservation
 * MSI just created. Best-effort — never blocks the order.
 */
class ReindexOnOrderPlace implements ObserverInterface
{
    public function __construct(private readonly StockSyncer $stockSyncer)
    {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order) {
            return;
        }
        $ids = [];
        foreach ($order->getAllItems() as $item) {
            $ids[] = (int) $item->getProductId();
        }
        $this->stockSyncer->syncByProductIds($ids);
    }
}
