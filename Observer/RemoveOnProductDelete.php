<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ParkkTech\FastMagento\Model\OpenSearch\StockSyncer;

/**
 * Drop a product's document from OpenSearch when it is deleted, so the serving layer never
 * hydrates a product that no longer exists (the mview reindex only tracks upserts).
 */
class RemoveOnProductDelete implements ObserverInterface
{
    public function __construct(private readonly StockSyncer $stockSyncer)
    {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if ($product && $product->getId()) {
            $this->stockSyncer->removeProduct((int) $product->getId());
        }
    }
}
