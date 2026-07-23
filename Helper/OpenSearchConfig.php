<?php

namespace ParkkTech\FastMagento\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;

class OpenSearchConfig extends AbstractHelper
{
    protected $scopeConfig; // ✅ Removed strict type declaration

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($context);
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Fast stock sync: patch just the stock fields of affected OpenSearch docs on a stock
     * change instead of a full EAV reprojection (falls back to a full reproject on any miss).
     *
     * Part of the Fast Checkout bundle: the master `enable_fast_checkout` toggle (default on)
     * implies it — same pattern as OS-serve and optimistic stock — so the whole fast pipeline
     * is on by default and turning Fast Checkout off disables it cleanly. The advanced
     * `fast_stock_sync` flag can still force it on by itself when the master is off.
     */
    public function isFastStockSyncEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('fastmagento/cart/enable_fast_checkout')
            || $this->scopeConfig->isSetFlag('fastmagento/cart/fast_stock_sync');
    }

    /**
     * ✅ Get OpenSearch Index Name from Configuration.
     */
    public function getIndexName(): string
    {
        // Fetch Magento's default OpenSearch prefix
        $defaultPrefix = $this->scopeConfig->getValue('catalog/search/opensearch_index_prefix') ?? 'magento2';

        // Fetch custom index prefix from FastMagento settings (admin-configurable)
        $customSuffix = $this->scopeConfig->getValue('fastmagento/indexing/opensearch_index_prefix') ?? 'products';

        return $defaultPrefix . '_' . $customSuffix;
    }

    /**
     * ✅ Category serving index name (sibling of the product index).
     * e.g. magento2_categories — same default prefix, admin-overridable suffix.
     */
    public function getCategoryIndexName(): string
    {
        $defaultPrefix = $this->scopeConfig->getValue('catalog/search/opensearch_index_prefix') ?? 'magento2';
        $customSuffix = $this->scopeConfig->getValue('fastmagento/indexing/opensearch_category_index_prefix') ?? 'categories';

        return $defaultPrefix . '_' . $customSuffix;
    }

    /**
     * ✅ Attribute-option dictionary index name (sibling of the product/category indexes).
     * Holds every option-bearing attribute's {option_id => label} set so option labels
     * (PDP additional attributes, layered-nav facets, swatches, search) resolve from OpenSearch
     * instead of eav_attribute_option* MySQL loads. e.g. magento2_attribute_options.
     */
    public function getAttributeOptionIndexName(): string
    {
        $defaultPrefix = $this->scopeConfig->getValue('catalog/search/opensearch_index_prefix') ?? 'magento2';
        $customSuffix = $this->scopeConfig->getValue('fastmagento/indexing/opensearch_option_index_prefix') ?? 'attribute_options';

        return $defaultPrefix . '_' . $customSuffix;
    }
}
