<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Checkout;

use Magento\Checkout\Model\Cart;
use Magento\Catalog\Model\Product;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;

class AddProductPlugin
{
    public function beforeAddProduct(Cart $subject, $productInfo, $requestInfo = null)
    {
        /** @var Product $product */
        $product = $productInfo;

        if ($product->getTypeId() === Configurable::TYPE_CODE && is_array($requestInfo)) {
            // Try to detect the child product using super_attribute
            $childProduct = $this->getSelectedChildProduct($product, $requestInfo);
            if ($childProduct) {
                $this->applyCatalogRulePrice($childProduct);
            }
        } else {
            $this->applyCatalogRulePrice($product);
        }

        return [$product, $requestInfo];
    }

    private function getSelectedChildProduct(Product $configurableProduct, array $requestInfo): ?Product
    {
        if (!isset($requestInfo['super_attribute'])) {
            return null;
        }

        /** @var Configurable $typeInstance */
        $typeInstance = $configurableProduct->getTypeInstance();
        $typeInstance->setStoreFilter($configurableProduct->getStoreId(), $configurableProduct);

        $usedProducts = $typeInstance->getUsedProducts($configurableProduct);
        foreach ($usedProducts as $childProduct) {
            $matches = true;
            foreach ($requestInfo['super_attribute'] as $attrId => $optionId) {
                if ((int) $childProduct->getData('attribute_' . $attrId) != (int) $optionId) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return $childProduct;
            }
        }

        return null;
    }

    private function applyCatalogRulePrice(Product $product): void
    {
        if ($product instanceof ShellNoEavProduct) {
            $rulePrice = $product['catalog_rule_price']['rule_price'] ?? null;
        } else {
            $rulePrice = $product->getPriceInfo()
                ->getPrice(\Magento\CatalogRule\Pricing\Price\CatalogRulePrice::PRICE_CODE)
                ->getValue();
        }

        if ($rulePrice !== null) {
            $product->setCustomPrice($rulePrice);
            $product->setOriginalCustomPrice($rulePrice);
            $product->setIsSuperMode(true);
        }
    }
}
