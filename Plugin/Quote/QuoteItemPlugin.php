<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Quote;

use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\CatalogRule\Pricing\Price\CatalogRulePrice;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

class QuoteItemPlugin
{
    public function afterGetProduct(QuoteItem $subject, $product)
    {
        // If product already has custom price injected, skip
        if ($product->getCustomPrice()) {
            return $product;
        }

        // Apply catalog rule price directly, resolved for the quote's customer group
        // (catalog rules are group-specific).
        if ($product instanceof ShellNoEavProduct) {
            $groupId = (int) $subject->getQuote()->getCustomerGroupId();
            $rulePrice = $product->getCatalogRulePriceForGroup($groupId);
        } else {
            $rulePrice = $product->getPriceInfo()
                ->getPrice(CatalogRulePrice::PRICE_CODE)
                ->getValue();
        }

        if ($rulePrice !== null) {
            $product->setCustomPrice($rulePrice);
            $product->setOriginalCustomPrice($rulePrice);
            $product->setIsSuperMode(true);
        }

        return $product;
    }
}
