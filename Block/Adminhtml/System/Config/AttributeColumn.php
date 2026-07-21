<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Block\Adminhtml\System\Config;

use Magento\Framework\View\Element\Html\Select;

/**
 * Select column for the searchable-attributes weight table: lists product attributes so an
 * admin picks the attribute to weight rather than typing its code.
 */
class AttributeColumn extends Select
{
    private const COMMON = [
        'name', 'sku', 'description', 'short_description', 'meta_keyword',
        'part_type', 'compatible_platforms', 'revision_code',
    ];

    public function setInputName($value)
    {
        return $this->setName($value);
    }

    public function setInputId($value)
    {
        return $this->setId($value);
    }

    protected function _toHtml()
    {
        if (!$this->getOptions()) {
            foreach (self::COMMON as $code) {
                $this->addOption($code, $code);
            }
        }
        return parent::_toHtml();
    }
}
