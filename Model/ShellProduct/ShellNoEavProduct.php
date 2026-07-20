<?php

namespace ParkkTech\FastMagento\Model\ShellProduct;

use Magento\Catalog\Model\FilterProductCustomAttribute;
use Magento\Catalog\Model\Product as CoreProduct;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\Data\CollectionFactory;
use Magento\Framework\DataObject;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\Product\Url;
use Magento\Catalog\Model\Product\Link;
use Magento\Catalog\Model\Product\Configuration\Item\OptionFactory as ItemOptionFactory;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\Catalog\Model\Product\OptionFactory as CatalogProductOptionFactory;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Catalog\Helper\Product as CatalogProductHelper;
use Magento\Framework\Filesystem;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Catalog\Model\Indexer\Product\Flat\Processor as ProductFlatIndexerProcessor;
use Magento\Catalog\Model\Indexer\Product\Price\Processor as ProductPriceIndexerProcessor;
use Magento\Catalog\Model\Indexer\Product\Eav\Processor as ProductEavIndexerProcessor;
use Magento\Catalog\Model\Product\Image\CacheFactory as ImageCacheFactory;
use Magento\Catalog\Model\ProductLink\CollectionProvider;
use Magento\Catalog\Model\Product\LinkTypeProvider;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\Data\ProductLinkExtensionFactory;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface;
use Magento\Catalog\Model\Product\Attribute\Backend\Media\EntryConverterPool;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;


class ShellNoEavProduct extends CoreProduct
{
    protected array $doc = [];
    protected ?PriceInfoInterface $priceInfo = null;
    protected CollectionFactory $collectionFactory;
    protected ProductAttributeRepositoryInterface $productAttributeRepository;
    private $urlFinder;
    private $categoryCollectionFactory;

    public function __construct(
        Context                             $context,
        Registry                            $registry,
        ExtensionAttributesFactory          $extensionFactory,
        AttributeValueFactory               $customAttributeFactory,
        StoreManagerInterface               $storeManager,
        ProductAttributeRepositoryInterface $metadataService,
        Url                                 $url,
        Link                                $productLink,
        ItemOptionFactory                   $itemOptionFactory,
        StockItemInterfaceFactory           $stockItemFactory,
        CatalogProductOptionFactory         $catalogProductOptionFactory,
        CoreProduct\Visibility              $catalogProductVisibility,
        Status                              $catalogProductStatus,
        MediaConfig                         $catalogProductMediaConfig,
        ProductType                         $catalogProductType,
        ModuleManager                       $moduleManager,
        CatalogProductHelper                $catalogProduct,
        ProductResource                     $resource,
        ProductResource\Collection          $resourceCollection,
        CollectionFactory                   $collectionFactory,
        Filesystem                          $filesystem,
        IndexerRegistry                     $indexerRegistry,
        ProductFlatIndexerProcessor         $productFlatIndexerProcessor,
        ProductPriceIndexerProcessor        $productPriceIndexerProcessor,
        ProductEavIndexerProcessor          $productEavIndexerProcessor,
        CategoryRepositoryInterface         $categoryRepository,
        ImageCacheFactory                   $imageCacheFactory,
        CollectionProvider                  $entityCollectionProvider,
        LinkTypeProvider                    $linkTypeProvider,
        ProductLinkInterfaceFactory         $productLinkFactory,
        ProductLinkExtensionFactory         $productLinkExtensionFactory,
        EntryConverterPool                  $mediaGalleryEntryConverterPool,
        DataObjectHelper                    $dataObjectHelper,
        JoinProcessorInterface              $joinProcessor,
        UrlFinderInterface $urlFinder,
        CategoryCollectionFactory $categoryCollectionFactory,
        array                               $data = [],
        ?\Magento\Eav\Model\Config           $config = null,
        ?FilterProductCustomAttribute        $filterCustomAttribute = null
    )
    {
        $this->collectionFactory = $collectionFactory;
        $this->productAttributeRepository = $metadataService;
        $this->urlFinder = $urlFinder;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        parent::__construct($context, $registry, $extensionFactory, $customAttributeFactory, $storeManager, $metadataService, $url, $productLink, $itemOptionFactory, $stockItemFactory, $catalogProductOptionFactory, $catalogProductVisibility, $catalogProductStatus, $catalogProductMediaConfig, $catalogProductType, $moduleManager, $catalogProduct, $resource, $resourceCollection, $collectionFactory, $filesystem, $indexerRegistry, $productFlatIndexerProcessor, $productPriceIndexerProcessor, $productEavIndexerProcessor, $categoryRepository, $imageCacheFactory, $entityCollectionProvider, $linkTypeProvider, $productLinkFactory, $productLinkExtensionFactory, $mediaGalleryEntryConverterPool, $dataObjectHelper, $joinProcessor);
    }

    public function setOsDoc(array $doc): void
    {
        $this->doc = $doc;
    }

    public function getData($key = '', $index = null)
    {
        return $key !== '' && isset($this->doc[$key]) && null == $index ? $this->doc[$key] : parent::getData($key, $index);
    }

    public function getId()
    {
        return $this->doc['entity_id'] ?? parent::getId();
    }

    public function load($productId, $field = null)
    {
        return $this;
    }

    public function afterLoad()
    {
        return $this;
    }

    public function getUrl($useSid = null)
    {
        return $this->getProductUrl();
    }

    /**
     * Get product url model
     *
     * @return Product\Url
     */
    public function getUrlModel()
    {
        return $this->_urlModel;
    }

    public function getMediaGalleryImages()
    {
        $mediaGallery = $this->doc['media_gallery'] ?? [];
        $collection = $this->collectionFactory->create();
        foreach ($mediaGallery as $images) {
            if (!is_array($images)) {
                continue;
            }
            foreach ($images as $image) {
                $collection->addItem(new DataObject([
                    'file' => $image['file'] ?? '',
                    'url' => $image['url'] ?? '',
                    'label' => $image['label'] ?? '',
                    'position' => $image['position'] ?? '',
                    'disabled' => $image['disabled'] ?? false,
                ]));
            }
        }
        return $collection;
    }

    public function getImage()
    {
        return $this->doc['image'] ?? parent::getImage();
    }

    public function getPrice()
    {
        return $this->getData('catalog_rule_price')['rule_price']
            ?? $this->doc['final_price']
            ?? parent::getPrice();
    }

    public function getFinalPrice($qty = null)
    {
        return $this->getData('catalog_rule_price')['rule_price']
            ?? $this->doc['final_price']
            ?? parent::getFinalPrice($qty);
    }

    public function getSpecialPrice()
    {
        return $this->doc['special_price'] ?? parent::getSpecialPrice();
    }

    public function getTierPrices()
    {
        return $this->doc['tier_prices'] ?? parent::getTierPrices();
    }


    public function getStockData()
    {
        return $this->doc['stock_data'] ?? [];
    }

    public function isSalable()
    {
        return !empty($this->doc['is_in_stock']) && $this->doc['is_in_stock'] === true;
    }

    public function getAttributeText($attributeCode)
    {
        return $this->doc[$attributeCode] ?? parent::getAttributeText($attributeCode);
    }

    public function getCategoryIds()
    {
        return $this->doc['category_ids'] ?? parent::getCategoryIds();
    }

    public function getCategoryNames()
    {
        return $this->doc['category_names'] ?? [];
    }

    public function getConfigurableAttributes()
    {
        return $this->doc['configurable_options'] ?? [];
    }

    public function getChildProducts()
    {
        return $this->doc['child_products'] ?? [];
    }

public function getProductUrl($useSid = null)
{
    $store = $this->_storeManager->getStore();
    $baseUrl = $store->getBaseUrl();

    // Check if Magento is configured to include categories in product URLs
    $includeCategory = $store->getConfig('catalog/seo/product_use_categories');

    // Try to find the product's URL using Magento's URL Rewrite system
    $requestPath = $this->getUrlRewrite($includeCategory);

    if ($requestPath) {
        return $baseUrl . $requestPath;
    }

    // Fallback: Generate basic URL if no rewrite exists
    return $baseUrl . 'catalog/product/view/id/' . $this->getId();
}

    /**
     * ✅ Fetch URL Rewrites for the Product (Category + Product URL)
     */
    private function getUrlRewrite(bool $includeCategory)
    {
        $requestPath = null;

        // First, check if we have a URL rewrite for this product
        $productId = $this->getId();
        $storeId = $this->_storeManager->getStore()->getId();

        $filterData = [
            'entity_type' => 'product',
            'entity_id' => $productId,
            'store_id' => $storeId
        ];

        if ($includeCategory) {
            // Find the best category to use in the URL
            $categoryIds = $this->getCategoryIds();
            if (!empty($categoryIds)) {
                $filterData['category_id'] = $categoryIds[0]; // Pick the first category
            }
        }

        $urlRewrite = $this->urlFinder->findOneByData($filterData);
        if ($urlRewrite && isset($urlRewrite->getRequestPath)) {
            $requestPath = $urlRewrite->getRequestPath();
        }

        return $requestPath;
    }

    public function _afterLoad()
    {
        return $this;
    }

    public function getPriceInfo()
    {
        return $this->priceInfo ?? parent::getPriceInfo();
    }
}

