<?php

namespace ParkkTech\FastMagento\Plugin\Downloadable;

use Magento\Downloadable\Block\Catalog\Product\Links;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

class LinksPlugin
{
    /**
     * Plugin to handle ShellNoEavProduct (which may not have full downloadable data)
     *
     * @param Links $subject
     * @param callable $proceed
     * @return mixed|string
     */
    public function aroundToHtml(Links $subject, callable $proceed)
    {
        try {
            $product = $subject->getProduct();

            // If this is a shell product without proper downloadable data, skip rendering
            if ($product instanceof ShellNoEavProduct) {
                // Return empty string for shell products to avoid errors
                // The link data is not fully available in shell products
                return '';
            }
        } catch (\Exception $e) {
            // If there's any error getting the product, return empty
            return '';
        }

        try {
            return $proceed();
        } catch (\Exception $e) {
            // Catch any errors during rendering of downloadable links
            // and return empty string instead of failing
            return '';
        }
    }
}
