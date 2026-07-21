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
     * ✅ Get Used Child Products (Check Registry First, then Core Database)
     */
    public function getUsedProducts($configurableProduct, $requiredAttributeIds = null)
    {
        $registryKey = 'child_products';

        // ✅ Check if data is in registry
        if ($this->registry->registry($registryKey)) {
            return $this->registry->registry($registryKey);
        }

        // ✅ Fallback to Magento Core Database if not found in registry
        $usedProducts = parent::getUsedProducts($configurableProduct);

        // ✅ Cache in registry for future use
        $this->registry->register($registryKey, $usedProducts);

        return $usedProducts;
    }
}
