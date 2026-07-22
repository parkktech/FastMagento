<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options for the configurable line-item name setting (cart / mini-cart / checkout).
 */
class ConfigurableLineName implements OptionSourceInterface
{
    /**
     * @return array<int, array{value:string,label:\Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'parent', 'label' => __('Configurable (parent) name — Magento default')],
            ['value' => 'child', 'label' => __('Simple (variant) name')],
        ];
    }
}
