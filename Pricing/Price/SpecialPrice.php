<?php

namespace ParkkTech\FastMagento\Pricing\Price;

use Magento\Catalog\Pricing\Price\SpecialPrice as CoreSpecialPrice;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

/**
 * Shell products index `special_price` as a plain float, defaulting to 0.0 when a product has
 * no special price. Core SpecialPrice::getValue() treats any non-null/non-false value as a
 * valid special price (0 !== null && 0 !== false), so a 0 becomes a $0.00 "special price" that
 * wins the FinalPrice/BasePrice min() — every shell (and every configurable variant's
 * optionPrices) then renders a final price of 0. Guard the shell case: a special price of 0 (or
 * negative) means "no special price" → return false so the regular / catalog-rule price stands.
 */
class SpecialPrice extends CoreSpecialPrice
{
    public function getValue()
    {
        $product = $this->getProduct();

        if ($product instanceof ShellNoEavProduct) {
            $special = $product->getSpecialPrice();
            if ($special === null || $special === false || (float) $special <= 0.0) {
                return false;
            }
        }

        return parent::getValue();
    }
}
