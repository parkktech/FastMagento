<?php

namespace ParkkTech\FastMagento\Plugin\Downloadable;

use Magento\Downloadable\Block\Catalog\Product\Links;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

class BlockLinksPlugin
{
    /**
     * Plugin to skip rendering for shell products
     *
     * @param Links $subject
     * @param callable $proceed
     * @return string
     */
    public function aroundToHtml(Links $subject, callable $proceed)
    {
        try {
            $product = $subject->getProduct();
            // If this is a shell product, return empty string
            if ($product instanceof ShellNoEavProduct) {
                return '';
            }
        } catch (\Exception $e) {
            // If any error, skip rendering
            return '';
        }

        try {
            return $proceed();
        } catch (\Exception $e) {
            // If rendering fails, return empty instead of error
            return '';
        }
    }
}
