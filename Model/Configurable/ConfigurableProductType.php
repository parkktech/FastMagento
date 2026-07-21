<?php

namespace ParkkTech\FastMagento\Model\Configurable;

use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as CoreConfigurable;
use Magento\Catalog\Model\ResourceModel\Product\Relation as ProductRelation;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Attribute\OptionProvider;
use Magento\ConfigurableProduct\Model\AttributeOptionProviderInterface;
use ParkkTech\FastMagento\Model\OpenSearch\ParentIdResolver;

class ConfigurableProductType extends CoreConfigurable
{
    private ParentIdResolver $parentIdResolver;

    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        ProductRelation $catalogProductRelation,
        ScopeResolverInterface $scopeResolver,
        AttributeOptionProviderInterface $attributeOptionProvider,
        OptionProvider $optionProvider,
        ParentIdResolver $parentIdResolver,
        $connectionName = null
    ) {
        parent::__construct(
            $context,
            $catalogProductRelation,
            $connectionName,
            $scopeResolver,
            $attributeOptionProvider,
            $optionProvider
        );
        $this->parentIdResolver = $parentIdResolver;
    }

    /**
     * Resolve a child's configurable parent ids from OpenSearch (indexed parent_ids)
     * instead of a catalog_product_super_link query per product.
     *
     * The previous version checked `if ($product->getData('parent_ids'))`, but the indexer
     * stores parent_ids = [] for every simple product with no parent — and [] is falsy, so
     * it fell through to the DB for the (common) no-parent case, firing thousands of
     * super_link queries on listing pages that call this per product (e.g. product sliders).
     *
     * A found OpenSearch doc is authoritative: an empty (or absent) parent_ids means the
     * product genuinely has no configurable parent — return [] with no DB query. Only when
     * the product is not in OpenSearch at all do we fall back to the native lookup.
     *
     * @param int $childId
     * @return int[]
     */
    public function getParentIdsByChild($childId)
    {
        $osParentIds = $this->parentIdResolver->getOsParentIds((int)$childId);
        // Found in OpenSearch: parent_ids is authoritative (empty = no configurable parent).
        if ($osParentIds !== null) {
            return $osParentIds;
        }
        // Not in OpenSearch — fall back to the native super_link lookup.
        return parent::getParentIdsByChild($childId);
    }
}
