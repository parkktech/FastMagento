<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Config\Source;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;

readonly class StoreList
{
    /**
     * @param StoreRepositoryInterface $storeRepository
     */
    public function __construct(
        private StoreRepositoryInterface $storeRepository
    ) {
    }

    /**
     * @return array
     */
    public function getStoreList(): array {
        $storeIds = [];

        /** @var StoreInterface $store */
        foreach ($this->storeRepository->getList() as $store) {
            if (0 == $store->getId()) {
                continue;
            }
            $storeIds[] = $store->getId();
        }

        return $storeIds;
    }
}
