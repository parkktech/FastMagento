<?php

namespace ParkkTech\FastMagento\Helper;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Catalog\Model\Product;
use ParkkTech\FastMagento\Model\ShellProduct\ShellProduct;
use ParkkTech\FastMagento\Model\ShellProduct\ShellProductFactory;
use ParkkTech\FastMagento\Model\ShellProduct\ShellDataProduct;
use ParkkTech\FastMagento\Model\ShellProduct\ShellDataProductFactory;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProductFactory;
use ParkkTech\FastMagento\Model\ShellProduct\ShellPriceFactory;
use ParkkTech\FastMagento\Model\ShellProduct\ShellPriceInfo;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Data\CollectionFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Registry;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable\AttributeFactory;
use Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface;
use Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory as EavAttributeFactory;
use ParkkTech\FastMagento\Model\ResourceModel\Product\Type\Configurable\Attribute\Collection as ConfigurableAttributeCollection;
use ParkkTech\FastMagento\Model\ResourceModel\Product\Type\Configurable\Attribute\CollectionFactory as ConfigurableAttributeCollectionFactory;

/**
 * Builds different "shell" product objects from OpenSearch doc:
 *
 * 1) buildProductFromOsDoc() -> ShellProduct (EAV-based)
 * 2) buildDataProductFromOsDoc() -> ShellDataProduct (pure ProductInterface, fails strict type)
 * 3) buildNoEavProductFromOsDoc() -> ShellNoEavProduct (ext. \Magento\Catalog\Model\Product, no DB load)
 */
class ShellProductBuilder
{
    /** @var ShellProductFactory */
    private $shellProductFactory;

    /** @var ShellPriceFactory */
    private $shellPriceFactory;

    /** @var ShellDataProductFactory */
    private $shellDataProductFactory;

    /** @var ShellNoEavProductFactory */
    private $shellNoEavProductFactory;
    private ProductAttributeMediaGalleryEntryInterfaceFactory $mediaGalleryEntryFactory;
    private ProductRepositoryInterface $productRepository;
    private DataObjectHelper $dataObjectHelper;

    private CollectionFactory $collectionFactory;


    /**
     * DI all factories so we never call ObjectManager directly.
     */
    public function __construct(
        ShellProductFactory $shellProductFactory,
        ShellPriceFactory $shellPriceFactory,
        ShellDataProductFactory $shellDataProductFactory,
        ShellNoEavProductFactory $shellNoEavProductFactory,
        ProductAttributeMediaGalleryEntryInterfaceFactory $mediaGalleryEntryFactory,
        ProductRepositoryInterface $productRepository,
        DataObjectHelper $dataObjectHelper,
        CollectionFactory $collectionFactory,
        private Registry $registry,
        private AttributeFactory $attributeFactory,
        private StockItemInterfaceFactory $stockItemInterfaceFactory,
        private JoinProcessorInterface $joinProcessor,
        private ConfigurableAttributeCollectionFactory $configurableAttributeCollectionFactory,
        private EavConfig $eavConfig,
        private EavAttributeFactory $eavAttributeFactory,
        private \Magento\Downloadable\Model\LinkFactory $downloadableLinkFactory,
        private \Magento\Downloadable\Model\SampleFactory $downloadableSampleFactory,
        private readonly \ParkkTech\FastMagento\Model\Projection\ExtensionAttributes $extensionProjection
    ) {
        $this->shellProductFactory = $shellProductFactory;
        $this->shellPriceFactory = $shellPriceFactory;
        $this->shellDataProductFactory = $shellDataProductFactory;
        $this->shellNoEavProductFactory = $shellNoEavProductFactory;
        $this->mediaGalleryEntryFactory = $mediaGalleryEntryFactory;
        $this->productRepository = $productRepository;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * (1) Build EAV-based ShellProduct (extends \Magento\Catalog\Model\Product).
     * Overhead if you load from DB, but you can skip load() and just store doc data.
     */
    public function buildProductFromOsDoc(array $doc): ShellNoEavProduct
    {
        /** @var ShellNoEavProduct $product */
        $product = $this->shellProductFactory->create();

        $product->setOsDoc($doc);

        // Example data
        $product->setId(isset($doc['entity_id']) ? (string) $doc['entity_id'] : 0);
        $product->setSku($doc['sku'] ?? null);
        $product->setName($doc['name'] ?? null);
        $product->setStoreId($doc['store_id'] ?? 0);
        $product->setWebsiteIds($doc['website_ids'] ?? [0]);

        // Indexed canonical request_path Magento\Catalog\Model\Product\Url::getUrl() uses it
        // directly and skips the url_rewrite DB finder (per-item URL N+1 on product grids/lists).
        if (!empty($doc['request_path'])) {
            $product->setData('request_path', $doc['request_path']);
        }

        if (!empty($doc['type_id'])) {
            $product->setTypeId($doc['type_id']);
        }

        $regular = isset($doc['price']) ? (float)$doc['price'] : 0.0;
        $product->setData('price', $regular);
        $product->setPrice($regular);

        $final = isset($doc['final_price']) ? (float)$doc['final_price'] : $regular;
        $product->setData('final_price', $final);
        $product->setFinalPrice($final);

        $special = isset($doc['special_price']) ? (float)$doc['special_price'] : 0.0;
        $product->setData('special_price', $special);
        $product->setSpecialPrice($special);

        if (!empty($doc['custom_attributes']) && is_array($doc['custom_attributes'])) {
            foreach ($doc['custom_attributes'] as $code => $val) {
                $product->setData($code, $val);
            }
        }

        if (!empty($doc['category_ids']) && is_array($doc['category_ids'])) {
            $product->setData('category_ids', $doc['category_ids']);
        }

        if (isset($doc['is_in_stock'])) {
            $product->setData('is_in_stock', $doc['is_in_stock']);
        }

        return $product;
    }

    /**
     * Register the in-cart child shell(s) under a configurable/composite parent so the
     * Configurable override's getUsedProducts()/getProductByAttributes() resolve to exactly the
     * variant(s) being purchased — no native used-product DB load, no ~660-child build. Used by
     * the cart/checkout serve path (buildNoEavProductFromOsDoc(..., hydrateChildren=false)).
     *
     * @param int $parentId
     * @param ShellNoEavProduct[] $childShells
     */
    public function registerCartChildren(int $parentId, array $childShells): void
    {
        $childShells = array_values(array_filter($childShells));
        if ($parentId <= 0 || !$childShells) {
            return;
        }
        $perIdKey = 'child_products_' . $parentId;
        $this->registry->unregister($perIdKey);
        $this->registry->register($perIdKey, $childShells);
        $this->registry->unregister('child_products');
        $this->registry->register('child_products', $childShells);
    }

    /**
     * (2) Build a pure "ShellDataProduct" (implements ProductInterface, no EAV).
     * BUT code strictly requiring \Magento\Catalog\Model\Product will fail.
     */
    public function buildDataProductFromOsDoc(array $doc): ShellDataProduct
    {
        $regular = isset($doc['price']) ? (float)$doc['price'] : 0.0;
        $final = isset($doc['final_price']) ? (float)$doc['final_price'] : $regular;
        $special = isset($doc['special_price']) ? (float)$doc['special_price'] : 0.0;

        $shellPriceInfo = new ShellPriceInfo(
            $this->shellPriceFactory,
            $regular,
            $final,
            $special
        );

        /** @var ShellDataProduct $shellDataProduct */
        $shellDataProduct = $this->shellDataProductFactory->create([
            'doc' => $doc,
            'priceInfo' => $shellPriceInfo
        ]);

        return $shellDataProduct;
    }

    /**
     * (3) Build a "ShellNoEavProduct" => real \Magento\Catalog\Model\Product sub-class,
     * no DB loads if you don't call ->load(). This satisfies strict type checks
     * (like Swissup) while skipping EAV overhead.
     */
    public function buildNoEavProductFromOsDoc(array $doc, bool $hydrateChildren = true): ShellNoEavProduct
    {
        /** @var ShellNoEavProduct $noEavProduct */
        $product = $this->shellNoEavProductFactory->create();

        if (isset($doc['extension_attributes'])) {
            $extensionAttributes = $product->getExtensionAttributes();
            if ($extensionAttributes) {
                /** @var StockItemInterface $stockItem */
                $stockItem = $this->stockItemInterfaceFactory->create();
                if (isset($doc['extension_attributes']['stock_item'])) {
                    $stockItem->setData($doc['extension_attributes']['stock_item']);
                    $extensionAttributes->setStockItem($stockItem);
                    if ($stockItem->getIsInStock() && $stockItem->getQty() > 0) {
                        $product->setSalable(true);
                    }
                }

                if (isset($doc['extension_attributes']['category_links'])) {
                    $extensionAttributes->setCategoryLinks($doc['extension_attributes']['category_links']);
                }
                if (isset($doc['extension_attributes']['configurable_product_links'])) {
                    $extensionAttributes->setConfigurableProductLinks($doc['extension_attributes']['configurable_product_links']);
                }

                $product->setExtensionAttributes($extensionAttributes);
                unset($doc['extension_attributes']);
            }
        }

        $product->setOsDoc($doc);

        // Set Core Product Fields
        $product->setId(isset($doc['entity_id']) ? (string) $doc['entity_id'] : 0);
        $product->setSku($doc['sku'] ?? null);
        $product->setName($doc['name'] ?? null);
        $product->setStoreId($doc['store_id'] ?? 0);
        $product->setWebsiteIds($doc['website_ids'] ?? [0]);

        // Indexed canonical request_path Magento\Catalog\Model\Product\Url::getUrl() uses it
        // directly and skips the url_rewrite DB finder (per-item URL N+1 on product grids/lists).
        if (!empty($doc['request_path'])) {
            $product->setData('request_path', $doc['request_path']);
        }

        if (!empty($doc['type_id'])) {
            $product->setTypeId($doc['type_id']);
        }

        // Set Status and Visibility as integers (critical for canShow checks)
        if (isset($doc['status'])) {
            $product->setStatus((int)$doc['status']);
        }
        if (isset($doc['visibility'])) {
            $product->setVisibility((int)$doc['visibility']);
        }

// Set Pricing Data
        $regular = isset($doc['price']) ? (float)$doc['price'] : 0.0;
        $product->setPrice($regular);
        $product->setData('price', $regular);

        $final = isset($doc['final_price']) ? (float)$doc['final_price'] : $regular;
        $product->setFinalPrice($final);
        $product->setData('final_price', $final);

        $special = isset($doc['special_price']) ? (float)$doc['special_price'] : null;
        $product->setSpecialPrice($special);
        $product->setData('special_price', $special);

// Set Stock Data
        if (!empty($doc['stock_data']) && is_array($doc['stock_data'])) {
            foreach ($doc['stock_data'] as $key => $value) {
                $product->setData("stock_$key", $value);
            }
        }

// Set Configurable Options
        if (!empty($doc['configurable_options_' . $doc['entity_id']]) && is_array($doc['configurable_options_' . $doc['entity_id']])) {
            $configurableOptions = $doc['configurable_options_' . $doc['entity_id']];

            /** @var ConfigurableAttributeCollection $attributesCollectionFactory */
            $attributesCollectionFactory = $this->configurableAttributeCollectionFactory->create();
            $attributesCollectionFactory = $attributesCollectionFactory->setProductFilter($product);
            $this->joinProcessor->process($attributesCollectionFactory);

            foreach ($configurableOptions as $configurableOption) {
                $attributeFactory = $this->attributeFactory->create();
                foreach ($configurableOption as $item => $value) {
                    if ($item == 'product_attribute') {

                        //May be, delete and use only continue.
                        /** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute $catalogEavAttribute */
                        $catalogEavAttribute = $this->eavAttributeFactory->create();
                        $catalogEavAttribute->setData($value);
                        $attributeFactory->setProductAttribute($catalogEavAttribute);
                        continue;

                    }

//                    if ($item == 'attribute_id') {
//                        $attribute = $this->eavConfig->getAttribute(\Magento\Catalog\Model\Product::ENTITY, $value);
//                        $attributeFactory->setProductAttribute($attribute);
//                        $attributeFactory->setData($item, $value);
//                        continue;
//                    }

                    $attributeFactory->setData($item, $value);
                }
                $attributesCollectionFactory->addItem($attributeFactory);
            }

            /**
             * It will skip loading of the collection with laod() method when ever looping on the attribute collection.
             */
            $attributesCollectionFactory->markLoaded();

            $product->setData('configurable_options', $doc['configurable_options_' . $doc['entity_id']]);
            $product->setData('_cache_instance_configurable_attributes', $attributesCollectionFactory);

            if ($this->registry->registry('configurable_options_' . $doc['entity_id'])) {
                $this->registry->unregister('configurable_options_' . $doc['entity_id']);
                $this->registry->register('configurable_options_' . $doc['entity_id'], $attributesCollectionFactory);
            } else {
                $this->registry->register('configurable_options_' . $doc['entity_id'], $attributesCollectionFactory);
            }
        }

// Set Tier Prices
        if (!empty($doc['tier_prices'])) {
            $product->setData('tier_prices', $doc['tier_prices']);
        }
        // Core TierPrice::getStoredTierPrices() reads getData('tier_price') (the singular
        // attribute code) and, when it isn't an array, calls the tier-price attribute
        // backend's afterLoad() — one catalog_product_entity_tier_price query per product.
        // Across a configurable's children (isTierPriceApplicable / hasSpecialPrice iterate
        // them) that is the ~660-query tier N+1. Always seed 'tier_price' with the indexed
        // rows mapped to the native shape (empty array when none), so getStoredTierPrices
        // short-circuits and never touches the DB.
        $product->setData('tier_price', $this->mapTierPricesToNative($doc['tier_prices'] ?? []));

// Set Catalog Rule Prices
        if (!empty($doc['catalog_rule_price'])) {
            $product->setData('catalog_rule_price', $doc['catalog_rule_price']);
        }

// Set Category Names
        if (!empty($doc['category_names'])) {
            $product->setData('category_names', $doc['category_names']);
        }

// Set Media Gallery Data
        if (!empty($doc['media_gallery']) && is_array($doc['media_gallery'])) {
            if (!$product->hasData('media_gallery')) {
                $product->setData('media_gallery', []);
            }
            $product = $this->setMediaGallery($product, $doc['media_gallery']);
        }

// Set All Other Custom Attributes Dynamically
        if (!empty($doc['attributes']) && is_array($doc['attributes'])) {
            foreach ($doc['attributes'] as $attributeCode => $value) {
                $value = $this->normalizeAttributeValue($value);
                $product->setData($attributeCode, $value);
            }
        }

// Child docs (configurable/grouped/bundle members) carry their data under
//    custom_attributes, with the super-attribute option ids kept raw (e.g. color=86,
//    size=89). Map them so getAllowProducts() sees an ENABLED child and the
//    configurable/swatch jsonConfig can match each child to its option + price.
        if (!empty($doc['custom_attributes']) && is_array($doc['custom_attributes'])) {
            $this->hydrateChildFromCustomAttributes($product, $doc);
        }

// Set Child Products
        if (!empty($doc['child_products']) && is_array($doc['child_products'])) {
            $product->setData('child_products', $doc['child_products']);

            $anyChildInStock = false;
            foreach ($doc['child_products'] as $child) {
                if (!empty($child['is_in_stock'])) {
                    $anyChildInStock = true;
                    if (!$hydrateChildren) {
                        break; // cart-mode: we only scan for salability; stop early.
                    }
                }
            }

            // CART/CHECKOUT MODE ($hydrateChildren=false): the selected variant is its OWN quote
            // item (served separately as a normal simple), so the parent needs NONE of its ~660
            // sibling variants built. That recursive build is the biggest quote-load cost. Skip
            // it; the caller (Quote\Item\Collection) registers just the in-cart child under
            // child_products_<parentId> via registerCartChildren() so getUsedProducts() resolves
            // cheaply. Parent salability comes from the raw-array scan above.
            if ($hydrateChildren) {
                $childProducts = [];
                foreach ($doc['child_products'] as $child) {
                    //Make recursive calls to this method to add all child products.
                    $childProducts[] = $this->buildNoEavProductFromOsDoc($child);
                }

                // Register children BOTH globally (legacy "last built" convention) AND keyed by
                // this parent's id. The global key is overwritten whenever any other configurable
                // is hydrated in the same request — so a cart with two configurables, or an
                // add-to-cart while another configurable sits in the quote, resolved options
                // against the WRONG parent's children and silently failed. The per-id key lets
                // getUsedProducts()/getProductByAttributes() fetch the right children regardless.
                $parentId = (int) ($doc['entity_id'] ?? 0);
                $this->registry->unregister('child_products');
                $this->registry->register('child_products', $childProducts);
                if ($parentId) {
                    $perIdKey = 'child_products_' . $parentId;
                    $this->registry->unregister($perIdKey);
                    $this->registry->register($perIdKey, $childProducts);
                }
            }

            // A composite parent (configurable/grouped/bundle) holds no stock of its own —
            // its stock_item is is_in_stock=0. Without this the parent shell is is_salable=0,
            // so AbstractType::isSalable() short-circuits to false BEFORE the type's
            // child-based salability check runs and the PDP shows "unavailable" (options
            // block never renders). Derive the parent's salability from its children.
            // ShellNoEavProduct::getData() reads the OS doc BEFORE _data, so the doc's
            // is_salable/is_in_stock would shadow these writes — strip them from the doc.
            if ($anyChildInStock) {
                // 'salable' is the key Product::isSalable() actually reads; 'is_salable'
                // feeds AbstractType::isSalable(). Set both, and strip every stock/salable
                // key from the doc so ShellNoEavProduct::getData() (doc-first) can't shadow.
                unset(
                    $doc['salable'],
                    $doc['is_salable'],
                    $doc['is_in_stock'],
                    $doc['quantity_and_stock_status']
                );
                $product->setOsDoc($doc);
                $product->setData('salable', true);
                $product->setData('is_salable', true);
                $product->setData('is_in_stock', true);
            }
        }

// Loop Through Remaining Keys and Set Any Unhandled Values
        $handledKeys = [
            'entity_id', 'sku', 'name', 'store_id', 'website_ids', 'type_id',
            'price', 'final_price', 'special_price', 'stock_data', 'configurable_options',
            'tier_prices', 'tier_price', 'catalog_rule_price', 'category_names', 'media_gallery',
            'attributes', 'child_products', 'status', 'visibility',
            'downloadable_links', 'downloadable_samples'
        ];

        foreach ($doc as $key => $value) {
            // Skip already handled keys
            if (in_array($key, $handledKeys, true)) {
                continue;
            }

            // Set Remaining Data Dynamically
            $product->setData($key, $value);
        }


        // Downloadable: hydrate Link/Sample models from the OS doc so native
        // getLinks()/getSamples() serve them without a DB round-trip.
        if (($doc['type_id'] ?? null) === 'downloadable') {
            $this->hydrateDownloadable($product, $doc);
        }

        // If you want a custom ShellPriceInfo:
        $catalogRulePrice = $doc['catalog_rule_price']['rule_price'] ?? null;
        $priceInfo = new ShellPriceInfo($this->shellPriceFactory, $regular, $final, $special, $catalogRulePrice);
        $product->setPriceInfo($priceInfo);

        // Final guard: drop the product type's RUNTIME object caches that a build step (or a
        // stale indexed doc) may have left as arrays in the model data. Served back they make
        // core treat arrays as attribute objects the cart page 500s in getUsedProductAttributes
        // getId(). Core rebuilds them live from the OS-hydrated data when next needed.
        $product->stripRuntimeCaches();
        $this->extensionProjection->hydrate($product, $doc['fm_extension_attributes'] ?? []);

        return $product;
    }

    /**
     * Rebuild downloadable Link/Sample models from the indexed arrays and pre-set them on
     * the product. Native Downloadable\Type::getLinks()/getSamples() return early when
     * these are already set, so the PDP renders titles/prices/samples with no downloadable
     * SQL. If the doc carries no link data we leave them unset and native falls back to its
     * own DB load (correct, just not OS-served).
     *
     * @param array<string, mixed> $doc
     */
    /**
     * Hydrate a configurable/grouped/bundle CHILD shell from its custom_attributes map.
     *
     * The indexer projects children as {price, is_in_stock, stock_qty, custom_attributes:{
     * type_id, status(label), color, size, ...}}. The parent read path reads type_id/status
     * top-level and stock from extension_attributes, so without this a child shell has no
     * type, is not ENABLED, and its super-attribute values are unset — getAllowProducts()
     * then drops it and the PDP shows "unavailable". Here we map type_id, resolve the status
     * label to Magento's numeric status, wire top-level stock to salable, and copy the raw
     * attribute values (incl. the super-attribute option ids the jsonConfig matches on).
     *
     * @param array<string, mixed> $doc
     */
    private function hydrateChildFromCustomAttributes(ShellNoEavProduct $product, array $doc): void
    {
        $ca = $doc['custom_attributes'];

        if (!empty($ca['type_id'])) {
            $product->setTypeId((string) $ca['type_id']);
        }

        // Status is indexed as its label ("Enabled"/"Disabled"); getAllowProducts() compares
        // the NUMERIC status, so map it back.
        $disabled = isset($ca['status']) && strcasecmp((string) $ca['status'], 'Disabled') === 0;
        $product->setStatus(
            $disabled
                ? \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED
                : \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
        );

        // Copy raw attribute values (super-attributes like color/size stay as option ids).
        // Skip the label-resolved system attrs whose labels would break strict getters.
        $skip = ['status', 'visibility', 'tax_class_id', 'quantity_and_stock_status', 'type_id'];
        foreach ($ca as $code => $value) {
            if (in_array($code, $skip, true)) {
                continue;
            }
            if (!$product->hasData($code)) {
                $product->setData($code, $value);
            }
        }

        // Stock: children carry is_in_stock/stock_qty top-level (no extension_attributes).
        $inStock = !empty($doc['is_in_stock']) && (float) ($doc['stock_qty'] ?? 0) > 0;
        $product->setData('is_salable', $inStock);
        $product->setData('is_in_stock', $inStock);
    }

    private function hydrateDownloadable(ShellNoEavProduct $product, array $doc): void
    {
        try {
            $links = [];
            foreach (($doc['downloadable_links'] ?? []) as $data) {
                if (!is_array($data) || empty($data['link_id'])) {
                    continue;
                }
                $link = $this->downloadableLinkFactory->create();
                $link->setData($data);
                $link->setId((int) $data['link_id']);
                $link->setProduct($product);
                $links[$link->getId()] = $link;
            }

            $samples = [];
            foreach (($doc['downloadable_samples'] ?? []) as $data) {
                if (!is_array($data) || empty($data['sample_id'])) {
                    continue;
                }
                $sample = $this->downloadableSampleFactory->create();
                $sample->setData($data);
                $sample->setId((int) $data['sample_id']);
                $samples[$sample->getId()] = $sample;
            }

            // ShellNoEavProduct::getData() reads the OS doc BEFORE _data, so the raw
            // link/sample arrays would shadow the hydrated models. Drop them from the doc
            // and re-store it, then write the Link/Sample models to _data — now visible to
            // native getLinks()/getSamples(), which return early instead of hitting the DB.
            unset($doc['downloadable_links'], $doc['downloadable_samples']);
            $product->setOsDoc($doc);
            $product->setDownloadableLinks($links);
            $product->setDownloadableSamples($samples);
        } catch (\Throwable $e) {
            // Leave downloadable data unset native DB fallback renders correctly.
        }
    }

    /**
     * Map indexed tier prices ({customer_group_id, qty, value}) to the native `tier_price`
     * attribute shape core's TierPrice price model consumes (price_qty / website_price /
     * price / cust_group / all_groups). Returns [] when there are none, which is enough for
     * getStoredTierPrices() to short-circuit without a DB load.
     *
     * @param array<int, array<string, mixed>> $tierPrices
     * @return array<int, array<string, mixed>>
     */
    private function mapTierPricesToNative(array $tierPrices): array
    {
        $allGroupsId = \Magento\Customer\Model\Group::CUST_GROUP_ALL;
        $native = [];
        foreach ($tierPrices as $tp) {
            if (!is_array($tp)) {
                continue;
            }
            $custGroup = (int)($tp['customer_group_id'] ?? $allGroupsId);
            $value = (float)($tp['value'] ?? 0);
            $native[] = [
                'price_qty'     => (float)($tp['qty'] ?? 1),
                'website_price' => $value,
                'price'         => $value,
                'cust_group'    => $custGroup,
                'all_groups'    => $custGroup === $allGroupsId ? 1 : 0,
                'website_id'    => 0,
            ];
        }
        return $native;
    }

    /**
     * Normalize attribute values from OpenSearch doc to match native Magento behavior.
     * Single-element arrays become scalars, multi-element arrays are comma-joined.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalizeAttributeValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        // Single-element array: extract the scalar
        if (count($value) === 1) {
            return reset($value);
        }

        // Multi-element array: join with commas (native multiselect behavior)
        if (count($value) > 1) {
            return implode(',', $value);
        }

        // Empty array: return empty string
        return '';
    }

    /**
     * @param ProductInterface $product
     * @param array $mediaGallery
     * @return ProductInterface
     */
    public function setMediaGallery(\Magento\Catalog\Api\Data\ProductInterface $product, array $mediaGallery)
    {
        if (empty($mediaGallery['images'])) {
            return $product;
        }

        $mediaEntries = [];
        $legacyImages = [];

        foreach ($mediaGallery['images'] as $imageData) {
            $file = $imageData['file'] ?? null;
            $mediaType = $imageData['media_type'] ?? 'image';
            $label = $imageData['label'] ?? '';
            $position = (int)($imageData['position'] ?? 0);
            $disabled = (bool)($imageData['disabled'] ?? false);

            $mediaGalleryEntry = $this->mediaGalleryEntryFactory->create();
            $mediaGalleryEntry->setFile($file);
            $mediaGalleryEntry->setLabel($label);
            $mediaGalleryEntry->setPosition($position);
            $mediaGalleryEntry->setDisabled($disabled);
            $mediaGalleryEntry->setMediaType($mediaType);
            $mediaEntries[] = $mediaGalleryEntry;

            $legacyImages[] = [
                'file' => $file,
                'media_type' => $mediaType,
                'label' => $label,
                'position' => $position,
                'disabled' => $disabled,
                'value_id' => null
            ];
        }

        $product->setMediaGalleryEntries($mediaEntries);
        $mediaGalleryArray = [
            'images' => $legacyImages,
            'values' => [] // optional
        ];
        $product->setData('media_gallery', $mediaGalleryArray);

        return $product;
    }
}
