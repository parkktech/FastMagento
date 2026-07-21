<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Observer;

use Magento\CatalogRule\Pricing\Price\CatalogRulePrice;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

/**
 * Apply the catalog-rule price to quote items, resolved for the quote's customer group.
 *
 * Catalog rules are group-specific, so we price each item for $quote->getCustomerGroupId()
 * (guest, Wholesale, Retailer, …). For configurable items the rule lives on the selected
 * child simple, not on the parent, so we resolve the child.
 */
class ApplyCatalogRulePrice implements ObserverInterface
{
    public function execute(Observer $observer)
    {
        $quote = $observer->getQuote();
        $groupId = (int) $quote->getCustomerGroupId();

        foreach ($quote->getAllVisibleItems() as $item) {
            $rulePrice = $this->resolveItemRulePrice($item, $groupId);

            if ($rulePrice !== null) {
                $item->setCustomPrice($rulePrice);
                $item->setOriginalCustomPrice($rulePrice);
                $item->getProduct()->setIsSuperMode(true);

                // Keep the parent configurable object (mini-cart reads it) in sync.
                if ($parentOption = $item->getOptionByCode('product_type')) {
                    $parentProduct = $parentOption->getProduct();
                    if ($parentProduct) {
                        $parentProduct->setCustomPrice($rulePrice);
                        $parentProduct->setFinalPrice($rulePrice);
                        $parentProduct->setOriginalCustomPrice($rulePrice);
                        $parentProduct->setIsSuperMode(true);
                    }
                }
            }
        }
    }

    /**
     * Rule price for a quote item, for the given customer group. For configurables the rule
     * is on the selected child simple, so resolve that; otherwise the item's own product.
     */
    private function resolveItemRulePrice(QuoteItem $item, int $groupId): ?float
    {
        $product = $item->getProduct();

        if ($product->getTypeId() === \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE) {
            $child = $this->getChildProduct($item);
            if ($child) {
                return $this->getRulePrice($child, $groupId);
            }
        }

        return $this->getRulePrice($product, $groupId);
    }

    /**
     * The selected child simple of a configurable quote item.
     *
     * @return \Magento\Catalog\Model\Product|null
     */
    private function getChildProduct(QuoteItem $item)
    {
        $option = $item->getOptionByCode('simple_product');
        if ($option && $option->getProduct()) {
            return $option->getProduct();
        }
        foreach ($item->getChildren() as $childItem) {
            if ($childItem->getProduct()) {
                return $childItem->getProduct();
            }
        }
        return null;
    }

    /**
     * Group-aware rule price for a product: from the indexed per-group map for a shell,
     * else the native catalog-rule price model (which uses the current session group).
     * Returns null when no rule applies.
     */
    private function getRulePrice($product, int $groupId): ?float
    {
        if ($product instanceof ShellNoEavProduct) {
            return $product->getCatalogRulePriceForGroup($groupId);
        }

        $value = $product->getPriceInfo()
            ->getPrice(CatalogRulePrice::PRICE_CODE)
            ->getValue();

        return $value !== false ? (float) $value : null;
    }
}
