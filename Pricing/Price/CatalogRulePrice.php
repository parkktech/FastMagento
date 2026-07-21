<?php

namespace ParkkTech\FastMagento\Pricing\Price;

use Magento\CatalogRule\Pricing\Price\CatalogRulePrice as CoreCatalogRulePrice;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

class CatalogRulePrice extends CoreCatalogRulePrice
{
    public function getValue()
    {
        $product = $this->getProduct();

        // ✅ Serve the catalog-rule price straight from the indexed doc, NEVER from SQL, and
        // resolve it for the CURRENT customer group. The indexer writes a per-group
        // `catalog_rule_prices` map on every shell (parent AND each configurable child), so a
        // guest, a Wholesale customer and a Retailer each get their own rule price. Returning
        // the resolved value — or false when no rule applies to this group — keeps the shell
        // off the catalogrule_product_price table. Falling through to parent::getValue() here
        // is what produced the ~660-query N+1 on a big configurable PDP (one getRulePrice()
        // per child) and would also be group-wrong.
        if ($product instanceof ShellNoEavProduct) {
            $rulePrice = $product->getCatalogRulePriceForCurrentGroup();
            return $rulePrice !== null ? $rulePrice : false;
        }

        return parent::getValue();
    }
}
