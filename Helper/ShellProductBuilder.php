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
        private EavAttributeFactory $eavAttributeFactory
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
        $product->setId($doc['entity_id'] ?? 0);
        $product->setSku($doc['sku'] ?? null);
        $product->setName($doc['name'] ?? null);
        $product->setStoreId($doc['store_id'] ?? 0);
        $product->setWebsiteIds($doc['website_ids'] ?? [0]);

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
    public function buildNoEavProductFromOsDoc(array $doc): ShellNoEavProduct
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

        // ✅ Set Core Product Fields
        $product->setId($doc['entity_id'] ?? 0);
        $product->setSku($doc['sku'] ?? null);
        $product->setName($doc['name'] ?? null);
        $product->setStoreId($doc['store_id'] ?? 0);
        $product->setWebsiteIds($doc['website_ids'] ?? [0]);

        if (!empty($doc['type_id'])) {
            $product->setTypeId($doc['type_id']);
        }

        // ✅ Set Status and Visibility as integers (critical for canShow checks)
        if (isset($doc['status'])) {
            $product->setStatus((int)$doc['status']);
        }
        if (isset($doc['visibility'])) {
            $product->setVisibility((int)$doc['visibility']);
        }

// ✅ Set Pricing Data
        $regular = isset($doc['price']) ? (float)$doc['price'] : 0.0;
        $product->setPrice($regular);
        $product->setData('price', $regular);

        $final = isset($doc['final_price']) ? (float)$doc['final_price'] : $regular;
        $product->setFinalPrice($final);
        $product->setData('final_price', $final);

        $special = isset($doc['special_price']) ? (float)$doc['special_price'] : null;
        $product->setSpecialPrice($special);
        $product->setData('special_price', $special);

// ✅ Set Stock Data
        if (!empty($doc['stock_data']) && is_array($doc['stock_data'])) {
            foreach ($doc['stock_data'] as $key => $value) {
                $product->setData("stock_$key", $value);
            }
        }

// ✅ Set Configurable Options
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

// ✅ Set Tier Prices
        if (!empty($doc['tier_prices'])) {
            $product->setData('tier_prices', $doc['tier_prices']);
        }

// ✅ Set Catalog Rule Prices
        if (!empty($doc['catalog_rule_price'])) {
            $product->setData('catalog_rule_price', $doc['catalog_rule_price']);
        }

// ✅ Set Category Names
        if (!empty($doc['category_names'])) {
            $product->setData('category_names', $doc['category_names']);
        }

// ✅ Set Media Gallery Data
        if (!empty($doc['media_gallery']) && is_array($doc['media_gallery'])) {
            if (!$product->hasData('media_gallery')) {
                $product->setData('media_gallery', []);
            }
            $product = $this->setMediaGallery($product, $doc['media_gallery']);
        }

// ✅ Set All Other Custom Attributes Dynamically
        if (!empty($doc['attributes']) && is_array($doc['attributes'])) {
            foreach ($doc['attributes'] as $attributeCode => $value) {
                $value = $this->normalizeAttributeValue($value);
                $product->setData($attributeCode, $value);
            }
        }

// ✅ Set Child Products
        if (!empty($doc['child_products']) && is_array($doc['child_products'])) {
            $product->setData('child_products', $doc['child_products']);

            $childProducts = [];
            foreach ($doc['child_products'] as $child) {
                //Make recursive calls to this method to add all child products.
                $childProducts[] = $this->buildNoEavProductFromOsDoc($child);
            }

            $this->registry->unregister('child_products');
            $this->registry->register('child_products', $childProducts);
        }

// ✅ Loop Through Remaining Keys and Set Any Unhandled Values
        $handledKeys = [
            'entity_id', 'sku', 'name', 'store_id', 'website_ids', 'type_id',
            'price', 'final_price', 'special_price', 'stock_data', 'configurable_options',
            'tier_prices', 'catalog_rule_price', 'category_names', 'media_gallery',
            'attributes', 'child_products', 'status', 'visibility',
            'downloadable_links', 'downloadable_samples'
        ];

        foreach ($doc as $key => $value) {
            // Skip already handled keys
            if (in_array($key, $handledKeys, true)) {
                continue;
            }

            // ✅ Set Remaining Data Dynamically
            $product->setData($key, $value);
        }


        // If you want a custom ShellPriceInfo:
        $catalogRulePrice = $doc['catalog_rule_price']['rule_price'] ?? null;
        $priceInfo = new ShellPriceInfo($this->shellPriceFactory, $regular, $final, $special, $catalogRulePrice);
        $product->setPriceInfo($priceInfo);

        return $product;
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
