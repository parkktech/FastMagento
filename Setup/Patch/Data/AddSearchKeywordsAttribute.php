<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the hidden, AI-populated `fm_search_keywords` product attribute.
 *
 * It is a plain text attribute with `is_searchable = 1` and a high search weight, so Magento's
 * native fulltext indexer folds it into the search index automatically (field name = attribute
 * code) and InstantSearch can weight it — no bespoke mapping. It is invisible everywhere on the
 * storefront and admin product form: it exists only to carry the per-product keyword/synonym
 * layer that `Model\Ai\SearchKeywordGenerator` fills, so e.g. a "UTV" product surfaces for
 * "side-by-side / SxS" even when the visible copy never says it.
 */
class AddSearchKeywordsAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'fm_search_keywords';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if ($eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE)) {
            return $this;
        }

        $eavSetup->addAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, [
            'type' => 'text',
            'label' => 'Search Keywords (AI)',
            'input' => 'textarea',
            'backend' => '',
            'frontend' => '',
            'source' => '',
            'required' => false,
            'user_defined' => true,
            'default' => '',
            // Searchable at a high weight → native fulltext indexer includes it and ranks it high.
            'searchable' => true,
            'search_weight' => 8,
            'filterable' => false,
            'filterable_in_search' => false,
            'comparable' => false,
            'visible' => false,
            'visible_on_front' => false,
            'visible_in_advanced_search' => false,
            'used_in_product_listing' => false,
            'used_for_sort_by' => false,
            'is_used_in_grid' => false,
            'is_visible_in_grid' => false,
            'is_filterable_in_grid' => false,
            'is_html_allowed_on_front' => false,
            'wysiwyg_enabled' => false,
            'unique' => false,
            'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
            'group' => '',
            'sort_order' => 100,
            'system' => false,
        ]);

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function getAliases(): array
    {
        return [];
    }
}
