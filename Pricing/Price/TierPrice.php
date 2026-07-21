<?php

namespace ParkkTech\FastMagento\Pricing\Price;

use Magento\Catalog\Pricing\Price\TierPrice as CoreTierPrice;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

class TierPrice extends CoreTierPrice
{
    public function getValue()
    {
        $product = $this->getProduct();

        // ✅ Serve tier prices from the indexed doc, NEVER from SQL. The indexer writes a
        // `tier_prices` key on every shell (empty array when the product has none), so an
        // empty result returns false — BasePrice then skips tier price for this product
        // without touching catalog_product_entity_tier_price. Falling through to
        // parent::getValue() (getStoredTierPrices → loadPriceData) per configurable child
        // is the other half of the ~660-query PDP N+1.
        if ($product instanceof ShellNoEavProduct) {
            $tierPrices = $product->getTierPrices();
            return empty($tierPrices) ? false : $tierPrices;
        }

        return parent::getValue();
    }
}
