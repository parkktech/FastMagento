<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Indexer;

use Psr\Log\LoggerInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use ParkkTech\FastMagento\Helper\WriteLog;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Eav\Api\AttributeRepositoryInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\CatalogRule\Model\ResourceModel\Rule as CatalogRuleResource;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;

class ProductIndexer implements ActionInterface, MviewActionInterface
{
    private $clientResolver;
    private $engineResolver;
    private $productCollectionFactory;
    private $scopeConfig;
    private $logger;
    private $configurableResource;
    private $catalogRuleResource;
    private $attributeRepository;

    private $searchCriteriaBuilder;

    private $openSearchConfig;


    public function __construct(
        ClientResolver $clientResolver,
        EngineResolverInterface $engineResolver,
        ProductCollectionFactory $productCollectionFactory,
        ConfigurableResource $configurableResource,
        CatalogRuleResource $catalogRuleResource,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        AttributeRepositoryInterface $attributeRepository,
        OpenSearchConfig $openSearchConfig,
        private ProductFactory $productFactory,
        private WriteLog $writeLog,
        private ProductRepositoryInterface $productRepository
    ) {
        $this->clientResolver = $clientResolver;
        $this->engineResolver = $engineResolver;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->configurableResource = $configurableResource;
        $this->catalogRuleResource = $catalogRuleResource;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->attributeRepository = $attributeRepository;
        $this->openSearchConfig = $openSearchConfig;
            }

    private function getDefaultMagentoAttributes(): array
    {
        return [
            'entity_id', 'attribute_set_id', 'type_id', 'sku', 'created_at', 'updated_at',
            'price', 'special_price', 'tier_price', 'weight', 'visibility', 'status',
            'tax_class_id', 'description', 'short_description', 'category_ids', 'media_gallery',
            'shipment_type', 'image', 'small_image', 'thumbnail', 'swatch_image', 'gallery',
            'url_key', 'meta_title', 'meta_keyword', 'meta_description', 'special_from_date',
            'special_to_date', 'cost', 'minimal_price', 'msrp', 'msrp_display_actual_price_type',
            'price_view', 'page_layout', 'options_container', 'custom_layout_update',
            'custom_layout_update_file', 'custom_design', 'custom_design_from', 'custom_design_to',
            'custom_layout', 'gift_message_available', 'old_id', 'url_path', 'required_options',
            'has_options', 'image_label', 'small_image_label', 'thumbnail_label', 'sku_type',
            'price_type', 'quantity_and_stock_status', 'weight_type', 'news_from_date', 'news_to_date',
            'country_of_manufacture', 'links_purchased_separately', 'samples_title',
            'links_title', 'links_exist', 'name', 'activity', 'new', 'sale'
        ];
    }

    public function executeRow($id): void
    {
        try {
            /** @var Product $product */
            $product = $this->productCollectionFactory->create()
                ->addAttributeToSelect('*')
                ->addFieldToFilter('entity_id', $id)
                ->getFirstItem();

            $productId = $product->getId();

            if (!$product || !$productId) {
                $this->writeLog->writeErrorLog("[FastMagento] Product ID $id not found.");
                return;
            }

            $productStoreIds = $product->getStoreIds();
            if (empty($productStoreIds)) {
                $this->writeLog->writeErrorLog('Product ID: ' . $productId . ' Has No Store IDs.');
                return;
            }

            foreach ($productStoreIds as $storeId) {
                /** @var Product $product */
                $product = $this->productRepository->getById($productId, false, $storeId, false);

                $productData = $this->prepareDoc($product);
                $productData = $this->setExtensionAttributes($productData);
                $doc = [
                    'id' => $product->getId(),
                    'body' => $productData
                ];
            }

            $indexName = $this->getIndexName();
            $client = $this->getSearchClient();

            $this->bulkIndexNDJSON($client, $indexName, [$doc]);

            $this->writeLog->writeInfoLog("[FastMagento] Product ID $id reindexed successfully.");
        } catch (\Exception $e) {
            $this->writeLog->writeErrorLog("[FastMagento] executeRow error: " . $e->getMessage());
        }
    }

    public function execute($ids)
    {
        $indexName = $this->getIndexName();
        $client = $this->getSearchClient();

        if (empty($ids)) {
            $collection = $this->productCollectionFactory->create();
            $collection->addAttributeToSelect('entity_id');
            foreach ($collection as $product) {
                $ids[] = $product->getEntityId();
            }
        }

        $docs = [];
        foreach ($ids as $id) {
            $productFactory = $this->productFactory->create();
            $product = $productFactory->load($id);

            $productId = $product->getId();

            if (!$product || !$productId) {
                continue;
            }

            $productStoreIds = $product->getStoreIds();
            if (empty($productStoreIds)) {
                $this->writeLog->writeErrorLog('Product ID: ' . $productId . ' Has No Store IDs.');
                continue;
            }

            foreach ($productStoreIds as $storeId) {
                /** @var Product $product */
                $product = $this->productRepository->getById($productId, false, $storeId, false);

                $body = $this->prepareDoc($product);
                $body = $this->setExtensionAttributes($body);

                $docs[] = [
                    'id' => (string)$product->getId(),
                    'body' => $body
                ];
            }
        }

        $this->bulkIndexNDJSON($client, $indexName, $docs);
    }

    private function setExtensionAttributes($body) {
        $extensionAttributes = isset($body['extension_attributes']) ? $body['extension_attributes'] : null;
        if (null !== $extensionAttributes && $extensionAttributes instanceof \Magento\Catalog\Api\Data\ProductExtension) {
            $stockItem = $extensionAttributes->getStockItem();
            if (null !== $stockItem) {
                $stockItemData = $stockItem->getData();

                $categoryLinks = $extensionAttributes->getCategoryLinks();

                $configurableProductLinks = [];
                if (isset($body['type_id']) && $body['type_id'] == 'configurable') {
                    $configurableProductLinks = $extensionAttributes->getConfigurableProductLinks();
                }

                //$body['extension_attributes']->setData('stock_item', $stockItemData);
                unset($body['extension_attributes']);
                $body['extension_attributes']['stock_item'] = $stockItemData;
                $body['extension_attributes']['configurable_product_links'] = $configurableProductLinks;
                $body['extension_attributes']['category_links'] = $categoryLinks;
            }
        }
        return $body;
    }

    public function executeFull()
    {
        $indexName = $this->getIndexName();
        $client = $this->getSearchClient();
        if ($client->indexExists($indexName)) {
            $client->deleteIndex($indexName);
        }
        $client->createIndex($indexName, $this->buildDynamicMapping());

        $this->execute([]);
    }

    public function executeList(array $ids)
    {
        $this->execute($ids);
    }

    private function getSearchClient()
    {
        $engineCode = $this->engineResolver->getCurrentSearchEngine();
        return $this->clientResolver->create($engineCode);
    }

    public function getIndexName(): string
    {
        return $this->openSearchConfig->getIndexName();
    }

    /**
     * @param $client
     * @param string $indexName
     * @param array $docs
     * @return void
     * @throws LocalizedException
     */
    private function bulkIndexNDJSON($client, string $indexName, array $docs): void
    {
        if (!$docs) {
            $this->logger->error('No documents to index.');
            return;
        }
        $lines = '';
        foreach ($docs as $doc) {
            $lines .= json_encode(['index' => ['_id' => $doc['id'], '_index' => $indexName]]) . "\n";
            $lines .= json_encode($doc['body']) . "\n";
        }
        $lines .= "\n";
        try {
            $response = $client->getOpenSearchClient()->bulk(['body' => $lines]);
            if (isset($response['errors']) && $response['errors']) {
                $this->logger->error('OpenSearch Bulk Errors: ' . json_encode($response, JSON_PRETTY_PRINT));
            } else {
                $this->logger->info('Bulk NDJSON Success: ' . json_encode($response, JSON_PRETTY_PRINT));
            }
        } catch (\Exception $e) {
            $this->logger->error('Bulk NDJSON error: ' . $e->getMessage());
            throw new LocalizedException(__('Bulk NDJSON error: %1', $e->getMessage()));
        }
    }

    private function buildDynamicMapping(): array
    {
        return [
            'settings' => [
                'analysis' => [
                    'analyzer' => [
                        'default' => ['type' => 'standard']
                    ]
                ]
            ],
            'mappings' => [
                'dynamic' => false,  // Keep OpenSearch from dynamically adding unknown fields
                'properties' => [
                    'attributes' => [
                        'type' => 'object',
                        'dynamic' => true,
                        'properties' => $this->getDynamicAttributeMapping()
                    ],
                    'tier_prices' => [
                        'type' => 'nested',
                        'properties' => [
                            'customer_group_id' => ['type' => 'integer'],
                            'qty'               => ['type' => 'float'],
                            'value'             => ['type' => 'float']
                        ]
                    ],
                    'parent_ids' => [
                        'type' => 'integer'
                    ]
                ]
            ]
        ];
    }


    private function isDefaultMagentoAttribute(string $attributeCode): bool
    {
        static $defaultAttributes = null;

        // ✅ Load default Magento attributes once (optimization)
        if ($defaultAttributes === null) {
            $defaultAttributes = $this->getDefaultMagentoAttributes();
        }

        return in_array($attributeCode, $defaultAttributes, true);
    }

    private function getDynamicAttributeMapping(): array
    {
        $mapping = [];

        // ✅ Use SearchCriteriaBuilder to fetch attributes properly
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $allAttributes = $this->attributeRepository->getList('catalog_product', $searchCriteria);

        foreach ($allAttributes->getItems() as $attribute) {
            $attributeCode = $attribute->getAttributeCode();

            // ✅ Ensure only custom attributes are indexed
            if ($this->isDefaultMagentoAttribute($attributeCode)) {
                continue;
            }

            // ✅ Assign dynamic type (we use 'keyword' to allow filtering & exact matching)
            $mapping[$attributeCode] = ['type' => 'keyword'];
        }

        return $mapping;
    }


    private function prepareDoc(\Magento\Catalog\Model\Product $product): array
    {
        $productData = $product->getData();
        $productData['created_at'] = $this->formatDateForOpenSearch($product->getCreatedAt());
        $productData['updated_at'] = $this->formatDateForOpenSearch($product->getUpdatedAt());
        $productData['category_names'] = $this->getCategoryNames($product);

        $productData['is_in_stock'] = (bool)$product->getExtensionAttributes()?->getStockItem()?->getIsInStock();
        $productData['stock_data'] = [
            'qty' => (float)$product->getExtensionAttributes()?->getStockItem()?->getQty()
        ];

        //  $productData['media_gallery'] = $this->getMediaGallery($product);
         $productData['child_products'] = $this->getChildProducts($product);
         $configOptions = "configurable_options_" . $productData['entity_id'];
         if ($product->getTypeId() == 'configurable') {
             $attributesData = [];
             $configurableAttributes = $product->getTypeInstance()->getConfigurableAttributes($product);
             /** @var \Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute $attribute */
             foreach ($configurableAttributes as $attribute) {
                 $attributesData[] = $attribute->getData();
             }

             foreach ($attributesData as &$currentAttributeData) {
                 $currentAttributeDataProductData = $currentAttributeData['product_attribute']->getData();
                 unset($currentAttributeData['product_attribute']);
                 $currentAttributeData['product_attribute'] = $currentAttributeDataProductData;
             }

             $productData[$configOptions] = $attributesData;

//             $productData[$configOptions] = $product->getTypeInstance()->getConfigurableOptions($product);
         } else {
             $productData[$configOptions] = [];
         }
        // $productData['custom_attributes'] = $this->getCustomAttributes($product);
        // Merge attribute values dynamically into $productData
        $productData['attributes'] = $this->getAttributeValues($product);
        // ✅ Add Tier Prices from MySQL to OpenSearch
        $productData['tier_prices'] = $this->getTierPrices($product);

        // ✅ Add Catalog Rule Prices from MySQL to OpenSearch
        $productData['catalog_rule_price'] = $this->getCatalogRulePrice($product);

        // ✅ Add Parent IDs for Simple Products (If Configurable)
        if ($product->getTypeId() === 'simple') {
            $productData['parent_ids'] = $this->getParentIds($product);
            $productData['final_price'] = (float)$product->getFinalPrice();
        }

        $productData['website_ids'] = $product->getWebsiteIds();
        $productData['store_id'] = $product->getStoreId();
        $productData['store_ids'] = $product->getStoreIds();

        return $productData;
    }

    private function getAttributeValues(\Magento\Catalog\Model\Product $product): array
    {
        $attributes = [];

        foreach ($product->getAttributes() as $attributeCode => $attribute) {
            if ($this->isDefaultMagentoAttribute($attributeCode)) {
                continue;
            }

            $value = $product->getData($attributeCode);
            $optionLabels = [];

            // ✅ Convert boolean values to "Yes" or "No"
            if (is_bool($value)) {
                $value = [$value ? 'Yes' : 'No'];
            }

            // ✅ Handle dropdown or multi-select attributes
            if ($attribute->usesSource()) {
                $source = $attribute->getSource();

                // Multi-Select Attribute (Comma-Separated Values)
                if (is_string($value) && strpos($value, ',') !== false) {
                    $optionIds = explode(',', $value);
                    foreach ($optionIds as $optionId) {
                        $optionLabel = $source->getOptionText(trim($optionId));
                        if (!empty($optionLabel)) {
                            $optionLabels[] = (string)$optionLabel;
                        }
                    }
                    $value = $optionLabels; // Store as an array
                } else {
                    // Single-Select Attribute (Dropdown)
                    $optionLabel = $source->getOptionText($value);
                    if (is_object($optionLabel)) {
                        $optionLabel = $optionLabel->getText();
                    }
                    $value = [$optionLabel]; // Store as an array
                }
            }

            // ✅ Ensure all values are stored as arrays
            $attributes[$attributeCode] = is_array($value) ? $value : [(string)$value];
        }

        return $attributes;
    }

    private function getCategoryNames(\Magento\Catalog\Model\Product $product): array
    {
        $categoryNames = [];
        foreach ($product->getCategoryCollection()->addAttributeToSelect('name') as $category) {
            $categoryNames[] = $category->getName();
        }
        return $categoryNames;
    }

    private function formatDateForOpenSearch($date)
    {
        return $date ? date('c', strtotime($date)) : null; // ISO 8601 format
    }


    /**
     * ✅ Fetch Tier Prices from `catalog_product_entity_tier_price`
     */
    private function getTierPrices(\Magento\Catalog\Model\Product $product): array
    {
        $tierPrices = [];
        $tierPriceCollection = $product->getTierPrice();

        if (!empty($tierPriceCollection)) {
            foreach ($tierPriceCollection as $tierPrice) {
                $tierPrices[] = [
                    'customer_group_id' => (int) $tierPrice['cust_group'],
                    'qty'               => (float) $tierPrice['price_qty'],
                    'value'             => (float) $tierPrice['price']
                ];
            }
        }

        return $tierPrices;
    }

    /**
     * ✅ Fetch Catalog Rule Prices from `catalogrule_product_price` Using Proper DI
     */
    private function getCatalogRulePrice(\Magento\Catalog\Model\Product $product): array
    {
        $catalogRulePriceData = [];

        $rulePrice = $this->catalogRuleResource->getRulePrice(
            new \DateTime(),
            1, // Website ID
            0, // Customer Group ID (adjust for multi-group support)
            $product->getId()
        );

        if (!empty($rulePrice)) {
            $catalogRulePriceData = [
                'rule_price' => (float)$rulePrice
            ];
        }

        return $catalogRulePriceData;
    }

    /**
     * ✅ Fetch Parent Configurable Product IDs Using Proper DI
     */
    private function getParentIds(\Magento\Catalog\Model\Product $product): array
    {
        return $this->configurableResource->getParentIdsByChild($product->getId());
    }


    public function getChildProducts(\Magento\Catalog\Model\Product $product)
    {
        $childProductsArray = [];
        $configurableOptions = [];

        switch ($product->getTypeId()) {
            case Configurable::TYPE_CODE:
                $childProducts = $product->getTypeInstance()->getUsedProducts($product);
                $options = $product->getTypeInstance()->getConfigurableOptions($product);
                foreach ($options as $option) {
                    foreach ($option as $item) {
                        if (isset($item['attribute_code']) && !in_array($item['attribute_code'], $configurableOptions)) {
                            $configurableOptions[] = $item['attribute_code'];
                        }
                    }
                }
                break;

            case Grouped::TYPE_CODE:
                $childProducts = $product->getTypeInstance()->getAssociatedProducts($product);
                break;

            case BundleType::TYPE_CODE:
                $selectionCollection = $product->getTypeInstance()->getSelectionsCollection(
                    $product->getTypeInstance()->getOptionsIds($product),
                    $product
                );
                $childProducts = iterator_to_array($selectionCollection);
                break;
        }


        // ✅ Convert child product objects to array for OpenSearch
       if(isset($childProducts)) {
           foreach ($childProducts as $child) {
               $child = $this->productFactory->create()->load($child->getId());
               $childProductsArray[] = [
                   'entity_id' => (int)$child->getId(),
                   'sku' => $child->getSku(),
                   'name' => $child->getName(),
                   'price' => (float)$child->getPrice(),
                   'final_price' => (float)$child->getFinalPrice(),
                   'special_price' => (float)$child->getSpecialPrice(),
                   'is_in_stock' => (bool)$child->getExtensionAttributes()?->getStockItem()?->getIsInStock(),
                   'stock_qty' => (float)$child->getExtensionAttributes()?->getStockItem()?->getQty(),
                   'image' => $child->getImage() ?? '',
                   'small_image' => $child->getSmallImage() ?? '',
                   'thumbnail' => $child->getThumbnail() ?? '',
                   'custom_attributes' => $this->getCustomAttributesArray($child, $configurableOptions),
                   'store_id' => $child->getStoreId(),
                   'store_ids' => $child->getStoreIds()
               ];
           }
       }


        return $childProductsArray;
    }

    private function getCustomAttributesArray(\Magento\Catalog\Model\Product $product, array $configurableOptions): array
    {
        $customAttributes = [];
        foreach ($product->getAttributes() as $attribute) {
            $attributeCode = $attribute->getAttributeCode();
            $value = $product->getData($attributeCode);

            if (in_array($attributeCode, $configurableOptions)) {
                $customAttributes[$attributeCode] = $value;
                continue;
            }

            if ($attribute->usesSource()) {
                $value = $product->getAttributeText($attributeCode);
            }

            if (!empty($value)) {
                $customAttributes[$attributeCode] = $value;
            }
        }
        return $customAttributes;
    }

    private function getConfigurableAttributes(\Magento\Catalog\Model\Product $product): array
    {
        $configurableAttributes = [];

        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return []; // Not a configurable product
        }

        /** @var \Magento\ConfigurableProduct\Model\Product\Type\Configurable $configType */
        $configType = $product->getTypeInstance();

        $attributes = $configType->getConfigurableAttributes($product);

        foreach ($attributes as $attribute) {
            $productAttribute = $attribute->getProductAttribute();
            if (!$productAttribute) {
                continue;
            }

            $attributeCode = $productAttribute->getAttributeCode();
            $configurableAttributes[$attributeCode] = [
                'id'     => (int) $productAttribute->getAttributeId(),
                'code'   => $attributeCode,
                'label'  => $productAttribute->getStoreLabel(),
                'values' => $attribute->getOptions(),
            ];
        }

        return $configurableAttributes;
    }

    private function getAttributeOptions(AbstractAttribute $attribute): array
    {
        $options = [];
        $source = $attribute->getSource();

        if ($source) {
            foreach ($source->getAllOptions() as $option) {
                if (!empty($option['value'])) {
                    $options[] = [
                        'id' => (int)$option['value'],
                        'label' => (string)$option['label'],
                    ];
                }
            }
        }
        return $options;
    }
}
