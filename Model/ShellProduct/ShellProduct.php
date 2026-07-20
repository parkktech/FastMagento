<?php

namespace ParkkTech\FastMagento\Model\ShellProduct;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductLinkExtensionFactory;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Helper\Product as CatalogProductHelper;
use Magento\Catalog\Model\Product as CoreProduct;
use Magento\Catalog\Model\Product\Attribute\Backend\Media\EntryConverterPool;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Link;
use Magento\Catalog\Model\Product\LinkTypeProvider;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Model\Product\OptionFactory as CatalogProductOptionFactory;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Url as ProductUrl;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product as ResourceProduct;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ProductLink\CollectionProvider;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface;
use Magento\Framework\Data\CollectionFactory;
use Magento\Framework\Model\Context;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Registry;
use Magento\Framework\Filesystem;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Catalog\Model\Indexer\Product as IndexerProduct;
use ParkkTech\FastMagento\Model\ShellProduct\ShellPriceFactory;
use ParkkTech\FastMagento\Model\ShellProduct\ShellPriceInfo;

/**
 * ShellProduct class that extends Magento\Catalog\Model\Product, fully
 * matching the core constructor signature, plus a required ShellPriceFactory param.
 *
 * Once you set a preference in etc/di.xml, Magento will instantiate this class
 * instead of the core class, ensuring that getPriceInfo() etc. return your custom
 * ShellPrice objects (so you don't get "call to a member function getValue() on float").
 */
class ShellProduct extends CoreProduct
{
    /**
     * OpenSearch doc data, if you want to store it for reference in getPriceInfo().
     */
    protected array $doc = [];

    /**
     * ShellPriceInfo instance, lazily created.
     */
    protected ?ShellPriceInfo $shellPriceInfo = null;

    /**
     * Factory for building ShellPrice objects from ShellPriceInfo.
     */
    protected ShellPriceFactory $shellPriceFactory;

    /**
     * Mirror the parent constructor's signature exactly,
     * but insert ShellPriceFactory as a required param BEFORE $data, $config, $filterCustomAttribute.
     *
     * @param Context $context
     * @param Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param ProductAttributeRepositoryInterface $metadataService
     * @param ProductUrl $url
     * @param Link $productLink
     * @param \Magento\Catalog\Model\Product\Configuration\Item\OptionFactory $itemOptionFactory
     * @param StockItemInterfaceFactory $stockItemFactory
     * @param CatalogProductOptionFactory $catalogProductOptionFactory
     * @param Visibility $catalogProductVisibility
     * @param Status $catalogProductStatus
     * @param MediaConfig $catalogProductMediaConfig
     * @param ProductType $catalogProductType
     * @param ModuleManager $moduleManager
     * @param CatalogProductHelper $catalogProduct
     * @param ResourceProduct $resource
     * @param ProductCollection $resourceCollection
     * @param CollectionFactory $collectionFactory
     * @param Filesystem $filesystem
     * @param IndexerRegistry $indexerRegistry
     * @param IndexerProduct\Flat\Processor $productFlatIndexerProcessor
     * @param IndexerProduct\Price\Processor $productPriceIndexerProcessor
     * @param IndexerProduct\Eav\Processor $productEavIndexerProcessor
     * @param CategoryRepositoryInterface $categoryRepository
     * @param \Magento\Catalog\Model\Product\Image\CacheFactory $imageCacheFactory
     * @param CollectionProvider $entityCollectionProvider
     * @param LinkTypeProvider $linkTypeProvider
     * @param ProductLinkInterfaceFactory $productLinkFactory
     * @param ProductLinkExtensionFactory $productLinkExtensionFactory
     * @param EntryConverterPool $mediaGalleryEntryConverterPool
     * @param DataObjectHelper $dataObjectHelper
     * @param JoinProcessorInterface $joinProcessor
     * @param ShellPriceFactory $shellPriceFactory   <-- inserted here as required
     * @param array $data                            <-- optional
     * @param EavConfig|null $config                 <-- optional
     * @param \Magento\Catalog\Model\FilterProductCustomAttribute|null $filterCustomAttribute <-- optional
     */
    public function __construct(
        Context $context,
        Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        ProductAttributeRepositoryInterface $metadataService,
        ProductUrl $url,
        Link $productLink,
        \Magento\Catalog\Model\Product\Configuration\Item\OptionFactory $itemOptionFactory,
        StockItemInterfaceFactory $stockItemFactory,
        CatalogProductOptionFactory $catalogProductOptionFactory,
        Visibility $catalogProductVisibility,
        Status $catalogProductStatus,
        MediaConfig $catalogProductMediaConfig,
        ProductType $catalogProductType,
        ModuleManager $moduleManager,
        CatalogProductHelper $catalogProduct,
        ResourceProduct $resource,
        ProductCollection $resourceCollection,
        CollectionFactory $collectionFactory,
        Filesystem $filesystem,
        IndexerRegistry $indexerRegistry,
        IndexerProduct\Flat\Processor $productFlatIndexerProcessor,
        IndexerProduct\Price\Processor $productPriceIndexerProcessor,
        IndexerProduct\Eav\Processor $productEavIndexerProcessor,
        CategoryRepositoryInterface $categoryRepository,
        \Magento\Catalog\Model\Product\Image\CacheFactory $imageCacheFactory,
        CollectionProvider $entityCollectionProvider,
        LinkTypeProvider $linkTypeProvider,
        ProductLinkInterfaceFactory $productLinkFactory,
        ProductLinkExtensionFactory $productLinkExtensionFactory,
        EntryConverterPool $mediaGalleryEntryConverterPool,
        DataObjectHelper $dataObjectHelper,
        JoinProcessorInterface $joinProcessor,
        ShellPriceFactory $shellPriceFactory,
        array $data = [],
        ?EavConfig $config = null,
        ?\Magento\Catalog\Model\FilterProductCustomAttribute $filterCustomAttribute = null
    ) {
        // Save your ShellPriceFactory
        $this->shellPriceFactory = $shellPriceFactory;

        // Call parent constructor EXACTLY with the matching parameters in correct order
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $storeManager,
            $metadataService,
            $url,
            $productLink,
            $itemOptionFactory,
            $stockItemFactory,
            $catalogProductOptionFactory,
            $catalogProductVisibility,
            $catalogProductStatus,
            $catalogProductMediaConfig,
            $catalogProductType,
            $moduleManager,
            $catalogProduct,
            $resource,
            $resourceCollection,
            $collectionFactory,
            $filesystem,
            $indexerRegistry,
            $productFlatIndexerProcessor,
            $productPriceIndexerProcessor,
            $productEavIndexerProcessor,
            $categoryRepository,
            $imageCacheFactory,
            $entityCollectionProvider,
            $linkTypeProvider,
            $productLinkFactory,
            $productLinkExtensionFactory,
            $mediaGalleryEntryConverterPool,
            $dataObjectHelper,
            $joinProcessor,
            $data,
            $config,
            $filterCustomAttribute
        );
    }

    /**
     * Store OpenSearch doc data if you want to retrieve in getPriceInfo() or other overrides.
     */
    public function setOsDoc(array $doc): void
    {
        $this->doc = $doc;
    }

    /**
     * Overridden getPriceInfo => returns a ShellPriceInfo with
     * (regular, final, special) from either $this->doc or Magento's data.
     */
    public function getPriceInfo()
    {
        if (!$this->shellPriceInfo) {
            $regular = (float)($this->doc['price']         ?? $this->getData('price')         ?? 0.0);
            $final   = (float)($this->doc['final_price']   ?? $this->getData('final_price')   ?? $regular);
            $special = (float)($this->doc['special_price'] ?? $this->getData('special_price') ?? 0.0);

            // Build ShellPriceInfo passing your ShellPriceFactory first
            $this->shellPriceInfo = new ShellPriceInfo(
                $this->shellPriceFactory,
                $regular,
                $final,
                $special
            );
        }
        return $this->shellPriceInfo;
    }

    /**
     * Override getPrice() => use getPriceInfo() => 'regular_price'.
     */
    public function getPrice()
    {
        // If you need quantity logic, pass it in.
        return $this->getPriceInfo()->getPrice('regular_price')->getValue();
    }

    /**
     * Override getFinalPrice($qty) => use 'final_price'.
     */
    public function getFinalPrice($qty = null)
    {
        return $this->getPriceInfo()->getPrice('final_price')->getValue();
    }

    /**
     * Override getSpecialPrice() => use 'special_price'.
     */
    public function getSpecialPrice()
    {
        return $this->getPriceInfo()->getPrice('special_price')->getValue();
    }

    /**
     * Optional override if you want a custom product URL.
     */
    public function getProductUrl($useSid = null)
    {
        // For demonstration, generate a "shell" style URL if you like:
        $urlKey = $this->getData('url_key') ?: ('shell-' . $this->getId());
        return '/' . $urlKey . '.html';
    }
}
