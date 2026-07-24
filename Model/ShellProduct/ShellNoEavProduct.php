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
use Magento\Store\Model\ScopeInterface;
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
use Magento\Framework\App\Config\ScopeConfigInterface;


class ShellNoEavProduct extends CoreProduct
{
    protected array $doc = [];
    protected ?PriceInfoInterface $priceInfo = null;
    protected CollectionFactory $collectionFactory;
    protected ProductAttributeRepositoryInterface $productAttributeRepository;
    private $urlFinder;
    private $categoryCollectionFactory;
    private $scopeConfig;
    private $customerGroupSession;
    private ?string $cachedUrl = null;

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
        array                               $data = [],
        ?\Magento\Eav\Model\Config           $config = null,
        ?FilterProductCustomAttribute        $filterCustomAttribute = null,
        // FastMagento deps are appended AFTER core's $data/$config/$filterCustomAttribute,
        // nullable with an ObjectManager fallback, so `array $data` keeps core Product's
        // parameter position. Core code paths (e.g. configurable child hydration) that
        // instantiate a product positionally pass $data into the right slot; placing these
        // required deps before $data shifted that slot and caused a constructor TypeError.
        ?UrlFinderInterface $urlFinder = null,
        ?CategoryCollectionFactory $categoryCollectionFactory = null,
        ?ScopeConfigInterface $scopeConfig = null,
        ?\Magento\Customer\Model\Session $customerGroupSession = null
    )
    {
        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $this->collectionFactory = $collectionFactory;
        $this->productAttributeRepository = $metadataService;
        $this->urlFinder = $urlFinder ?? $om->get(UrlFinderInterface::class);
        $this->categoryCollectionFactory = $categoryCollectionFactory ?? $om->get(CategoryCollectionFactory::class);
        $this->scopeConfig = $scopeConfig ?? $om->get(ScopeConfigInterface::class);
        $this->customerGroupSession = $customerGroupSession ?? $om->get(\Magento\Customer\Model\Session::class);
        parent::__construct($context, $registry, $extensionFactory, $customAttributeFactory, $storeManager, $metadataService, $url, $productLink, $itemOptionFactory, $stockItemFactory, $catalogProductOptionFactory, $catalogProductVisibility, $catalogProductStatus, $catalogProductMediaConfig, $catalogProductType, $moduleManager, $catalogProduct, $resource, $resourceCollection, $collectionFactory, $filesystem, $indexerRegistry, $productFlatIndexerProcessor, $productPriceIndexerProcessor, $productEavIndexerProcessor, $categoryRepository, $imageCacheFactory, $entityCollectionProvider, $linkTypeProvider, $productLinkFactory, $productLinkExtensionFactory, $mediaGalleryEntryConverterPool, $dataObjectHelper, $joinProcessor);
    }

    public function setOsDoc(array $doc): void
    {
        // Keep the product type's RUNTIME object caches (_cache_instance_*) out of the doc — they
        // get accidentally serialized into the index and, served back, are stale JSON arrays that
        // make core treat arrays as attribute objects (cart 500 in getUsedProductAttributes →
        // getId()). {@see stripRuntimeCaches} clears them from the model data at build end too.
        foreach (array_keys($doc) as $key) {
            if (strncmp((string) $key, '_cache_instance', 15) === 0) {
                unset($doc[$key]);
            }
        }
        $this->doc = $doc;
    }

    /**
     * Drop the product type's runtime object caches from the model data so core rebuilds them
     * live. Called at the end of the shell build, after any step that may have re-populated them.
     */
    public function stripRuntimeCaches(): void
    {
        foreach (array_keys($this->_data) as $key) {
            if (strncmp((string) $key, '_cache_instance', 15) === 0) {
                unset($this->_data[$key]);
            }
        }
    }

    public function getData($key = '', $index = null)
    {
        // Never serve `_cache_instance_*` from the indexed doc. These are the product type's
        // RUNTIME object caches (configurable attributes, used products, etc.) that get
        // accidentally serialized into the index during projection; served back as stale JSON
        // arrays they make core treat arrays as objects — e.g. getUsedProductAttributes()
        // returns arrays and the cart page 500s on getId(). Fall through to the live object
        // cache (set by ShellProductBuilder / rebuilt by core) instead.
        if ($key !== '' && $index === null && isset($this->doc[$key]) && strncmp($key, '_cache_instance', 15) !== 0) {
            return $this->doc[$key];
        }
        return parent::getData($key, $index);
    }

    /**
     * Guard: core blocks (e.g. Catalog\Block\Product\View\Attributes::getAdditionalData)
     * iterate getAttributes() and call methods expecting a real AbstractAttribute. On the
     * OS-hydrated product some attribute sets surface non-object entries, which throws a
     * TypeError mid-render. Return only valid attribute objects so the contract holds.
     *
     * @param int|null $groupId
     * @param bool $skipSuper
     * @return \Magento\Eav\Model\Entity\Attribute\AbstractAttribute[]
     */
    public function getAttributes($groupId = null, $skipSuper = false)
    {
        return array_filter(
            parent::getAttributes($groupId, $skipSuper),
            static fn($attribute) => $attribute instanceof \Magento\Eav\Model\Entity\Attribute\AbstractAttribute
        );
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

    /**
     * The OS-served shell never loads the media_gallery_entries extension attribute (load() is a
     * no-op), so core getMediaGalleryEntries() returns null for a shell. Magento\Swatches\Helper\
     * Data::getProductMediaGallery() foreach's over the result, so a null 500s the
     * swatches/ajax/media endpoint on EVERY swatch selection (Luma and Breeze alike — Breeze's
     * swatch-renderer just surfaces it by crashing on the 500). Coalesce null -> [] so the endpoint
     * degrades to the base image instead of erroring; if core can build entries from the doc's
     * media_gallery they still flow through and the per-variant gallery swap works. Same null->[]
     * guard as getOptions().
     *
     * @return \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface[]
     */
    public function getMediaGalleryEntries()
    {
        return parent::getMediaGalleryEntries() ?? [];
    }

    /**
     * Regular (list) price — MUST be the base price, never the catalog-rule price. Core
     * RegularPrice::getValue() reads this for the strikethrough "old price"; returning the
     * rule price here made the discounted price show as its own "was" price. The rule is
     * applied only through the FinalPrice/CatalogRulePrice models (and getFinalPrice()).
     */
    public function getPrice()
    {
        return $this->doc['price'] ?? parent::getPrice();
    }

    public function getFinalPrice($qty = null)
    {
        $rulePrice = $this->getCatalogRulePriceForCurrentGroup();
        return $rulePrice
            ?? $this->doc['final_price']
            ?? parent::getFinalPrice($qty);
    }

    /**
     * Customer group whose catalog-rule price applies to this product instance.
     *
     * On a quote/checkout line, Quote\Item::setProduct() stamps the QUOTE's customer group
     * onto the product (setCustomerGroupId) BEFORE totals read getFinalPrice(). Prefer that
     * stamped value: it is authoritative and, crucially, area-independent — the webapi_rest
     * and graphql checkout flows have NO storefront customer session, so reading the session
     * there returns 0 and would price EVERY logged-in customer as a guest (e.g. a Wholesale
     * shopper silently overcharged the group-0 price). Fall back to the session only when the
     * group was never stamped, i.e. non-quote contexts like the PDP.
     */
    private function getCurrentCustomerGroupId(): int
    {
        if ($this->hasData('customer_group_id')) {
            return (int) $this->getData('customer_group_id');
        }
        try {
            return (int) $this->customerGroupSession->getCustomerGroupId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Catalog-rule price for a specific customer group from the indexed per-group map, or
     * null when no rule applies to that group. Falls back to the legacy group-0 scalar for
     * docs indexed before the per-group map existed.
     *
     * @param int $groupId
     * @return float|null
     */
    public function getCatalogRulePriceForGroup(int $groupId): ?float
    {
        // New format: per-group map is authoritative. A group absent from the map has NO
        // rule (return null) — do NOT fall back to the group-0 scalar, or e.g. a Retailer
        // with no rule would inherit the guest discount.
        if (array_key_exists('catalog_rule_prices', $this->doc)) {
            $map = $this->doc['catalog_rule_prices'];
            // json_decode gives string keys; PHP array access normalizes "0" -> 0.
            if (is_array($map) && array_key_exists($groupId, $map)) {
                return (float) $map[$groupId];
            }
            return null;
        }
        // Legacy doc (indexed before the per-group map): fall back to the group-0 scalar.
        $scalar = $this->doc['catalog_rule_price']['rule_price'] ?? null;
        return $scalar !== null ? (float) $scalar : null;
    }

    /**
     * Catalog-rule price for the CURRENT customer group (guest, Wholesale, Retailer, …).
     *
     * @return float|null
     */
    public function getCatalogRulePriceForCurrentGroup(): ?float
    {
        return $this->getCatalogRulePriceForGroup($this->getCurrentCustomerGroupId());
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
        // ShellProductBuilder resolves salability from OpenSearch into the 'salable' flag
        // (a composite parent is salable when any child is in stock; a simple/child
        // reflects its own indexed stock). Honour it, and skip core Product::isSalable()'s
        // 'salable'-cache/getOrigData dance which is unreliable for a never-DB-loaded shell.
        if ($this->hasData('salable')) {
            return (bool) $this->getData('salable');
        }
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

    /**
     * Always return an array of custom options. The shell skips the native load that would
     * initialise the parent's `_options`, so it can be null — and core templates such as
     * product/view/options.phtml call count() on it directly, which fatals under PHP 8
     * (count(null)) on any product whose OS doc carries no options. This catalogue has no custom
     * options, so [] is correct; a store that uses them keeps native behaviour on a serving miss.
     */
    public function getOptions()
    {
        $options = parent::getOptions();
        return is_array($options) ? $options : [];
    }

public function getProductUrl($useSid = null)
{
    // Return cached URL if already resolved
    if ($this->cachedUrl !== null) {
        return $this->cachedUrl;
    }

    $store = $this->_storeManager->getStore();
    $baseUrl = $store->getBaseUrl();

    // Try to build URL from OpenSearch doc first (no DB query)
    $urlPath = $this->doc['url_path'] ?? $this->doc['url_key'] ?? null;

    if ($urlPath) {
        // Get product URL suffix from config
        $urlSuffix = $this->scopeConfig->getValue(
            'catalog/seo/product_url_suffix',
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        ) ?? '';

        // Build URL: baseUrl + urlPath + suffix
        $this->cachedUrl = $baseUrl . $urlPath . $urlSuffix;
        return $this->cachedUrl;
    }

    // Fallback: Check if Magento is configured to include categories in product URLs
    $includeCategory = $store->getConfig('catalog/seo/product_use_categories');

    // Fall back to URL Rewrite system only when url_path is missing
    $requestPath = $this->getUrlRewrite($includeCategory);

    if ($requestPath) {
        $this->cachedUrl = $baseUrl . $requestPath;
        return $this->cachedUrl;
    }

    // Last resort: Generate basic URL if no rewrite exists
    $this->cachedUrl = $baseUrl . 'catalog/product/view/id/' . $this->getId();
    return $this->cachedUrl;
}

    /**
     * ✅ Fetch URL Rewrites for the Product (Category + Product URL)
     * NOTE: This method is only called as a fallback when url_path is not available in OpenSearch doc.
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

