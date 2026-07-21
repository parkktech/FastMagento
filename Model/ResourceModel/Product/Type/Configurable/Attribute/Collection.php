<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\ResourceModel\Product\Type\Configurable\Attribute;

class Collection extends \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable\Attribute\Collection
{
    public function markLoaded(): void
    {
        $this->_setIsLoaded(true);
    }
}
