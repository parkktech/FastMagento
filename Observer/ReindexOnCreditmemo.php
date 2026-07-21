<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ParkkTech\FastMagento\Model\OpenSearch\StockSyncer;

/**
 * On refund / credit-memo (return), reproject the returned products (and parents) into
 * OpenSearch so a back-to-stock restock is reflected immediately. Best-effort.
 */
class ReindexOnCreditmemo implements ObserverInterface
{
    public function __construct(private readonly StockSyncer $stockSyncer)
    {
    }

    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getEvent()->getCreditmemo();
        if (!$creditmemo) {
            return;
        }
        $ids = [];
        foreach ($creditmemo->getAllItems() as $item) {
            $ids[] = (int) $item->getProductId();
        }
        $this->stockSyncer->syncByProductIds($ids);
    }
}
