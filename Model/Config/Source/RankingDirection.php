<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class RankingDirection implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'desc', 'label' => __('Highest first (descending)')],
            ['value' => 'asc', 'label' => __('Lowest first (ascending)')],
        ];
    }
}
