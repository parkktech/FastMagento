<?php

namespace ParkkTech\FastMagento\Plugin\Downloadable;

use Magento\Downloadable\Model\Product\Type;
use Magento\Catalog\Model\Product;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

class TypePlugin
{
    /**
     * Plugin to handle getLinks() for ShellNoEavProduct
     * Returns empty array instead of trying to load from database
     *
     * @param Type $subject
     * @param callable $proceed
     * @param Product $product
     * @return array
     */
    public function aroundGetLinks(Type $subject, callable $proceed, $product)
    {
        // If this is a shell product, return empty array
        // Shell products don't have full downloadable data
        if ($product instanceof ShellNoEavProduct) {
            return [];
        }

        return $proceed($product);
    }

    /**
     * Plugin to handle getSamples() for ShellNoEavProduct
     *
     * @param Type $subject
     * @param callable $proceed
     * @param Product $product
     * @return array
     */
    public function aroundGetSamples(Type $subject, callable $proceed, $product)
    {
        // If this is a shell product, return empty array
        if ($product instanceof ShellNoEavProduct) {
            return [];
        }

        return $proceed($product);
    }
}
