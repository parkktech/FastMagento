<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Quote;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Store\Model\ScopeInterface;

/**
 * Admin-controlled line-item name for configurable products on cart / mini-cart / checkout.
 *
 * By default Magento shows the CONFIGURABLE PARENT name on a configurable line (e.g.
 * "Keira Banded Underwire Bra") with the chosen Color/Size beneath. Some stores prefer the
 * specific SIMPLE (child) name instead. This plugin lets the merchant choose via
 * `fastmagento/cart/configurable_line_name` (parent | child; default parent = no change).
 *
 * getName() is the single source the cart block, the mini-cart customer-data section, and the
 * checkout quote-item REST payload all read, so overriding it here covers all three surfaces.
 * Gated so it only rewrites a CONFIGURABLE parent line that actually has an in-cart child; every
 * other item (simple, downloadable, the hidden child row) is returned untouched.
 */
class ConfigurableLineNamePlugin
{
    private const XML_PATH_NAME_SOURCE = 'fastmagento/cart/configurable_line_name';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param QuoteItem $subject
     * @param string|null $result
     * @return string|null
     */
    public function afterGetName(QuoteItem $subject, $result)
    {
        if ($subject->getProductType() !== \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE) {
            return $result;
        }
        if ($this->scopeConfig->getValue(self::XML_PATH_NAME_SOURCE, ScopeInterface::SCOPE_STORE) !== 'child') {
            return $result; // 'parent' (default) → keep the configurable name
        }

        // The purchased simple is the parent line's single child quote item.
        $children = $subject->getChildren();
        $child = $children ? reset($children) : null;
        if ($child) {
            // Read the child's own product name directly (avoid recursing through this plugin).
            $childProduct = $child->getProduct();
            $name = $childProduct ? $childProduct->getName() : $child->getData('name');
            if (!empty($name)) {
                return $name;
            }
        }
        return $result;
    }
}
