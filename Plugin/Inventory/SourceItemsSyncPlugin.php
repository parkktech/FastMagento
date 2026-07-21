<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Inventory;

use ParkkTech\FastMagento\Model\OpenSearch\StockSyncer;

/**
 * Keep OpenSearch live when MSI stock is written through the inventory API (admin product
 * grid, imports, ERP integrations, bin/magento inventory:* — anything that saves or deletes
 * source items). afterExecute collects the touched SKUs and reprojects those products
 * (+ parents) into the index. Registered on both SourceItemsSaveInterface and
 * SourceItemsDeleteInterface.
 */
class SourceItemsSyncPlugin
{
    public function __construct(private readonly StockSyncer $stockSyncer)
    {
    }

    /**
     * @param mixed $subject
     * @param mixed $result
     * @param \Magento\InventoryApi\Api\Data\SourceItemInterface[] $sourceItems
     * @return mixed
     */
    public function afterExecute($subject, $result, array $sourceItems)
    {
        $skus = [];
        foreach ($sourceItems as $sourceItem) {
            $skus[] = $sourceItem->getSku();
        }
        $this->stockSyncer->syncBySkus($skus);

        return $result;
    }
}
