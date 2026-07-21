<?php

namespace ParkkTech\FastMagento\Pricing\Price;

use Magento\CatalogRule\Pricing\Price\CatalogRulePrice as CoreCatalogRulePrice;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

class CatalogRulePrice extends CoreCatalogRulePrice
{
    public function getValue()
    {
        $product = $this->getProduct();

        // ✅ Serve the catalog-rule price straight from the indexed doc, NEVER from SQL.
        // The indexer writes a `catalog_rule_price` key on every shell (parent AND each
        // configurable child): ['rule_price' => X] when a rule applies, [] when none does.
        // Returning the indexed value — or false when the key is present but empty —
        // keeps the shell off the catalogrule_product_price table. Falling through to
        // parent::getValue() here is what produced the ~660-query N+1 on a big
        // configurable PDP, since each child shell hit getRulePrice() once.
        if ($product instanceof ShellNoEavProduct) {
            $data = $product->getData('catalog_rule_price');
            if (is_array($data) && array_key_exists('rule_price', $data)) {
                return $data['rule_price'];
            }
            return false;
        }

        return parent::getValue();
    }
}
