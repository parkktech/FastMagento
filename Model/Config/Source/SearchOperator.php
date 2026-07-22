<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * How multiple query terms combine in InstantSearch (Sphinx-style operator control).
 */
class SearchOperator implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'any', 'label' => __('Any term (OR) — broadest, most results')],
            ['value' => 'most', 'label' => __('Most terms (75%) — balanced')],
            ['value' => 'all', 'label' => __('All terms (AND) — strictest, most precise')],
        ];
    }
}
