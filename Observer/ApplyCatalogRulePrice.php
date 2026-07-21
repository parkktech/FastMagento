<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ApplyCatalogRulePrice implements ObserverInterface
{
    public function execute(Observer $observer)
    {
        $quote = $observer->getQuote();

        foreach ($quote->getAllVisibleItems() as $item) {
            $product = $item->getProduct();

            // Check for shell-based catalog rule price
            if ($product instanceof \ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct) {
                $rulePrice = $product['catalog_rule_price']['rule_price'] ?? null;
            } else {
                // Fallback for real EAV product
                $rulePrice = $product->getPriceInfo()
                    ->getPrice(\Magento\CatalogRule\Pricing\Price\CatalogRulePrice::PRICE_CODE)
                    ->getValue();
            }

            if ($rulePrice !== null) {
                $item->setCustomPrice($rulePrice);
                $item->setOriginalCustomPrice($rulePrice);
                $product->setIsSuperMode(true);

                // ✅ Inject catalog rule price into parent configurable product object (which mini cart uses)
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
}
