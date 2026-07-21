<?php

namespace ParkkTech\FastMagento\Model\Product\Type;

use Magento\ConfigurableProduct\Model\Product\Type\Configurable as CoreConfigurable;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Option;
use Magento\Eav\Model\Config;
use Magento\Framework\Event\ManagerInterface;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\Framework\Filesystem;
use Magento\Framework\Registry;
use Psr\Log\LoggerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\ConfigurableFactory;
use Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory as EavAttributeFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable\AttributeFactory as ConfigAttributeFactor;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as CatalogProductTypeConfigurable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Collection\SalableProcessor;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\File\UploaderFactory;

class Configurable extends CoreConfigurable
{
    private Registry $registry;

    public function __construct(
        Option $catalogProductOption,
        Config $eavConfig,
        \Magento\Catalog\Model\Product\Type $catalogProductType,
        ManagerInterface $eventManager,
        Database $fileStorageDb,
        Filesystem $filesystem,
        Registry $registry,
        LoggerInterface $logger,
        ProductRepositoryInterface $productRepository,
        ConfigurableFactory $typeConfigurableFactory,
        EavAttributeFactory $eavAttributeFactory,
        ConfigAttributeFactor $configurableAttributeFactory,
        ProductCollectionFactory $productCollectionFactory,
        AttributeCollectionFactory $attributeCollectionFactory,
        CatalogProductTypeConfigurable $catalogProductTypeConfigurable,
        ScopeConfigInterface $scopeConfig,
        JoinProcessorInterface $extensionAttributesJoinProcessor,
        ?FrontendInterface $cache = null,
        ?Session $customerSession = null,
        ?Json $serializer = null,
        ?ProductInterfaceFactory $productFactory = null,
        ?SalableProcessor $salableProcessor = null,
        ?ProductAttributeRepositoryInterface $productAttributeRepository = null,
        ?SearchCriteriaBuilder $searchCriteriaBuilder = null,
        ?UploaderFactory $uploaderFactory = null


    ) {
        parent::__construct(
            $catalogProductOption,
            $eavConfig,
            $catalogProductType,
            $eventManager,
            $fileStorageDb,
            $filesystem,
            $registry,
            $logger,
            $productRepository,
            $typeConfigurableFactory,
            $eavAttributeFactory,
            $configurableAttributeFactory,
            $productCollectionFactory,
            $attributeCollectionFactory,
            $catalogProductTypeConfigurable,
            $scopeConfig,
            $extensionAttributesJoinProcessor,
            $cache,
            $customerSession,
            $serializer,
            $productFactory,
            $salableProcessor,
            $productAttributeRepository,
            $searchCriteriaBuilder,
            $uploaderFactory
        );

        $this->registry = $registry;

    }

    /**
     * ✅ Get Configurable Attributes (Check Registry First, then Core Database)
     */
    public function getConfigurableAttributes($product)
    {
        $registryKey = 'configurable_options_' . $product->getId();

        // ✅ Check if data is in registry
        if ($this->registry->registry($registryKey)) {
            return $this->registry->registry($registryKey);
        }

        // ✅ Fallback to Magento Core Database if not found in registry
        $configurableAttributes = parent::getConfigurableAttributes($product);

        // ✅ Cache in registry for future use
        $this->registry->register($registryKey, $configurableAttributes);

        return $configurableAttributes;
    }

    /**
     * ✅ Salability from the OpenSearch-derived data instead of a DB linked-product
     * collection. Core Configurable::isSalable() runs getLinkedProductCollection() +
     * salableProcessor (product SQL) and, for an OS-hydrated shell whose store/link-field
     * differ from a native load, returns 0 → the PDP shows "unavailable" and the options
     * block never renders. The ShellProductBuilder already sets is_salable on the parent
     * from its children's stock, so honour that flag; only fall back to core when it is
     * absent (e.g. a natively-loaded configurable that never went through the shell).
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return bool
     */
    public function isSalable($product)
    {
        if ($product->hasData('is_salable')) {
            return (bool) $product->getData('is_salable')
                && (int) $product->getStatus() === \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED;
        }

        return parent::isSalable($product);
    }

    /**
     * ✅ Resolve the child product for a super-attribute selection from the OpenSearch-
     * hydrated children instead of getUsedProductCollection(). Core matches by running a DB
     * used-product collection with addAttributeToFilter(); for a shell parent that collection
     * yields nothing, so add-to-cart fails with "You need to choose options". Here we compare
     * each requested option id against the child docs already in the registry and return the
     * matched child (a cart-usable product via the repository). Falls back to core when the
     * registry isn't populated (natively-loaded configurable).
     *
     * @param array<int,int|string> $attributesInfo attribute_id => option_id
     * @param \Magento\Catalog\Model\Product $product
     * @return \Magento\Catalog\Model\Product|null
     */
    public function getProductByAttributes($attributesInfo, $product)
    {
        $hasChildren = $this->registry->registry('child_products_' . (int) $product->getId())
            || $this->registry->registry('child_products');
        if (is_array($attributesInfo) && !empty($attributesInfo) && $hasChildren) {
            $codeByAttributeId = [];
            foreach ($this->getConfigurableAttributes($product) as $attribute) {
                $productAttribute = $attribute->getProductAttribute();
                if ($productAttribute) {
                    $codeByAttributeId[(int) $attribute->getAttributeId()] = $productAttribute->getAttributeCode();
                }
            }

            foreach ($this->getUsedProducts($product) as $child) {
                $matched = true;
                foreach ($attributesInfo as $attributeId => $optionId) {
                    $code = $codeByAttributeId[(int) $attributeId] ?? null;
                    if ($code === null || (string) $child->getData($code) !== (string) $optionId) {
                        $matched = false;
                        break;
                    }
                }
                if ($matched && $child->getId()) {
                    return $this->productRepository->getById((int) $child->getId());
                }
            }
            return null;
        }

        return parent::getProductByAttributes($attributesInfo, $product);
    }

    /**
     * ✅ Get Used Child Products (Check Registry First, then Core Database)
     */
    public function getUsedProducts($configurableProduct, $requiredAttributeIds = null)
    {
        // Prefer this product's own child set (per-id key), so a second configurable in the
        // same request can't return the first one's children; fall back to the legacy global
        // key, then to the native DB load.
        $perIdKey = 'child_products_' . (int) $configurableProduct->getId();
        if ($this->registry->registry($perIdKey)) {
            return $this->registry->registry($perIdKey);
        }
        if ($this->registry->registry('child_products')) {
            return $this->registry->registry('child_products');
        }

        // ✅ Fallback to Magento Core Database if not found in registry
        $usedProducts = parent::getUsedProducts($configurableProduct);

        // ✅ Cache in registry for future use
        $this->registry->register($registryKey, $usedProducts);

        return $usedProducts;
    }
}
