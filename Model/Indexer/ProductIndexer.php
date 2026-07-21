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
use Magento\Store\Model\StoreManagerInterface;
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
    /**
     * Bulk-flush chunk size. Docs are streamed to OpenSearch in chunks of this
     * size so memory stays flat and the index fills incrementally instead of
     * accumulating every doc in one array and flushing once at the very end
     * (which OOMs at scale and leaves the index empty for the whole run).
     */
    private const FLUSH_SIZE = 200;

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

    private $storeManager;

    /**
     * Per-run cache of attribute option labels: [attributeCode => [optionId => label]].
     * Built once per attribute (a single getAllOptions() load) and reused for every
     * product, instead of a getOptionText() DB round-trip per option per product.
     *
     * @var array<string, array<string, string>>
     */
    private array $optionLabelCache = [];


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
        StoreManagerInterface $storeManager,
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
        $this->storeManager = $storeManager;
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
            $id = (int)$id;
            /** @var Product $product */
            $product = $this->productRepository->getById($id, false, $this->getIndexStoreId(), false);

            if (!$product->getId()) {
                $this->writeLog->writeErrorLog("[FastMagento] Product ID $id not found.");
                return;
            }

            $productData = $this->prepareDoc($product);
            $productData = $this->setExtensionAttributes($productData);
            $doc = [
                'id' => (string)$product->getId(),
                'body' => $productData
            ];

            $indexName = $this->getIndexName();
            $client = $this->getSearchClient();

            $this->bulkIndexNDJSON($client, $indexName, [$doc]);

            $this->writeLog->writeInfoLog("[FastMagento] Product ID $id reindexed successfully.");
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog("[FastMagento] executeRow error: " . $e->getMessage());
        }
    }

    public function execute($ids)
    {
        $indexName = $this->getIndexName();
        $client = $this->getSearchClient();
        $storeId = $this->getIndexStoreId();

        if (empty($ids)) {
            // Stream entity ids set-based; never materialise full products just to list ids.
            $connection = $this->productCollectionFactory->create()->getConnection();
            $ids = $connection->fetchCol(
                $connection->select()
                    ->from($this->productCollectionFactory->create()->getMainTable(), ['entity_id'])
            );
        }

        $docs = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            try {
                // ONE full load per product, scoped to the store we serve — instead of
                // an initial factory->load() plus a getById() reload per store view
                // (the old path did ~3 full EAV loads per product).
                /** @var Product $product */
                $product = $this->productRepository->getById($id, false, $storeId, false);
            } catch (\Throwable $e) {
                // Product missing/unloadable — skip, never abort the whole reindex.
                $this->writeLog->writeErrorLog('Product ID: ' . $id . ' not loadable: ' . $e->getMessage());
                continue;
            }

            if (!$product->getId()) {
                continue;
            }

            try {
                $body = $this->prepareDoc($product);
                $body = $this->setExtensionAttributes($body);
                $docs[] = [
                    'id' => (string)$product->getId(),
                    'body' => $body
                ];
            } catch (\Throwable $e) {
                // Never let one product's attribute/data quirk abort a full reindex.
                $this->writeLog->writeErrorLog(
                    'Product ID: ' . $id . ' skipped during indexing: ' . $e->getMessage()
                );
                continue;
            }

            // Stream to OpenSearch in chunks: flat memory, incremental progress.
            if (count($docs) >= self::FLUSH_SIZE) {
                $this->bulkIndexNDJSON($client, $indexName, $docs);
                $docs = [];
            }
        }

        if ($docs) {
            $this->bulkIndexNDJSON($client, $indexName, $docs);
        }
    }

    /**
     * Store view whose values are projected into the (single, global) serving index.
     * The read path fetches docs by id with no store scope, so one doc per product is
     * served; index the default frontend store view's values (matches what renders).
     * Per-store indexing is tracked separately (multi-store scope).
     */
    private function getIndexStoreId(): int
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();
            if ($store && (int)$store->getId() > 0) {
                return (int)$store->getId();
            }
        } catch (\Throwable $e) {
            // fall through to store 1
        }
        return 1;
    }

    /**
     * Warm-on-miss (read-through): project an ALREADY-LOADED product straight into
     * OpenSearch. Used by the frontend read-path fallback so a product that wasn't in
     * the index (mid-reindex / stale / never indexed) is added on first access and the
     * next request is served from OpenSearch — self-healing, like a cache miss.
     *
     * Takes the loaded product object directly (no reload) so it can't re-enter the
     * frontend load fallback that called it.
     */
    public function indexProductObject(\Magento\Catalog\Model\Product $product): void
    {
        try {
            if (!$product->getId()) {
                return;
            }
            $body = $this->prepareDoc($product);
            $body = $this->setExtensionAttributes($body);
            $this->bulkIndexNDJSON(
                $this->getSearchClient(),
                $this->getIndexName(),
                [['id' => (string)$product->getId(), 'body' => $body]]
            );
        } catch (\Throwable $e) {
            // Never let a warm-on-miss write break the page it is serving.
            $this->writeLog->writeErrorLog(
                'Warm-on-miss index failed for product ' . $product->getId() . ': ' . $e->getMessage()
            );
        }
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

             // Index the FULL swatch data so the PDP can render swatches + price/image
             // switching entirely from OpenSearch (no product SQL on read). Map:
             // [attribute_id => [option_id => {type, value, label}]] for every
             // super-attribute option (type 1=visual color hex, 2=visual image, 0=text).
             $superAttrIds = [];
             foreach ($attributesData as $ad) {
                 if (!empty($ad['attribute_id'])) {
                     $superAttrIds[] = (int)$ad['attribute_id'];
                 }
             }
             $productData['swatch_options'] = $this->getSwatchOptions($superAttrIds);

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

            // ✅ Handle dropdown or multi-select attributes (skip when the source model is unavailable)
            $source = $this->safeGetSource($attribute);
            if ($source) {

                // Multi-Select Attribute (Comma-Separated Values)
                if (is_string($value) && strpos($value, ',') !== false) {
                    $optionIds = explode(',', $value);
                    foreach ($optionIds as $optionId) {
                        $optionLabel = $this->resolveOptionLabel($attributeCode, $source, trim($optionId));
                        if (!empty($optionLabel)) {
                            $optionLabels[] = (string)$optionLabel;
                        }
                    }
                    $value = $optionLabels; // Store as an array
                } else {
                    // Single-Select Attribute (Dropdown)
                    $optionLabel = $this->resolveOptionLabel($attributeCode, $source, $value);
                    $value = [$optionLabel]; // Store as an array
                }
            }

            // ✅ Ensure all values are stored as arrays
            $attributes[$attributeCode] = is_array($value) ? $value : [(string)$value];
        }

        return $attributes;
    }

    /**
     * Resolve a select/multiselect option id to its label using a per-run cache.
     *
     * The label map for each attribute is built once (a single getAllOptions()
     * load) and reused across every product, replacing the previous
     * getOptionText()-per-option-per-product DB round-trips. Falls back to
     * getOptionText() for ids the source doesn't enumerate (e.g. custom sources),
     * so output labels stay identical to the old path.
     *
     * @param string $attributeCode
     * @param \Magento\Eav\Model\Entity\Attribute\Source\SourceInterface $source
     * @param mixed $optionId
     * @return string
     */
    private function resolveOptionLabel(string $attributeCode, $source, $optionId): string
    {
        $optionId = (string)$optionId;
        if ($optionId === '') {
            return '';
        }

        if (!isset($this->optionLabelCache[$attributeCode])) {
            $map = [];
            try {
                foreach ($source->getAllOptions() as $opt) {
                    if (isset($opt['value']) && $opt['value'] !== '') {
                        $label = $opt['label'];
                        if (is_object($label)) {
                            $label = $label->getText();
                        }
                        $map[(string)$opt['value']] = (string)$label;
                    }
                }
            } catch (\Throwable $e) {
                $map = [];
            }
            $this->optionLabelCache[$attributeCode] = $map;
        }

        if (array_key_exists($optionId, $this->optionLabelCache[$attributeCode])) {
            return $this->optionLabelCache[$attributeCode][$optionId];
        }

        // Fallback: source that doesn't enumerate this id via getAllOptions().
        try {
            $label = $source->getOptionText($optionId);
            if (is_object($label)) {
                $label = $label->getText();
            }
            return $label === null ? '' : (string)$label;
        } catch (\Throwable $e) {
            return '';
        }
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


        if (empty($childProducts)) {
            return $childProductsArray;
        }

        // Collect child ids from the type instance's already-resolved child list.
        $childIds = [];
        foreach ($childProducts as $child) {
            $cid = (int)$child->getId();
            if ($cid) {
                $childIds[$cid] = $cid;
            }
        }
        if (!$childIds) {
            return $childProductsArray;
        }
        $childIds = array_values($childIds);

        // ONE set-based collection load for ALL children (replaces a full ->load() per
        // child — the N+1 that made a 660-child configurable take ~60s to project).
        $childCollection = $this->productCollectionFactory->create();
        $childCollection->addAttributeToSelect('*')
            ->addFieldToFilter('entity_id', ['in' => $childIds]);
        $childCollection->load();

        // ONE batch stock query for all children (replaces getStockItem() per child).
        $stockMap = $this->getStockMap($childIds);
        // Children inherit the parent's store scope; compute once (avoids getStoreIds() per child).
        $parentStoreId  = $product->getStoreId();
        $parentStoreIds = $product->getStoreIds();

        foreach ($childCollection as $child) {
            $cid = (int)$child->getId();
            $stock = $stockMap[$cid] ?? ['qty' => 0.0, 'is_in_stock' => false];
            $childProductsArray[] = [
                'entity_id' => $cid,
                'sku' => $child->getSku(),
                'name' => $child->getName(),
                'price' => (float)$child->getPrice(),
                'final_price' => (float)$child->getFinalPrice(),
                'special_price' => (float)$child->getSpecialPrice(),
                'is_in_stock' => (bool)$stock['is_in_stock'],
                'stock_qty' => (float)$stock['qty'],
                'image' => $child->getImage() ?? '',
                'small_image' => $child->getSmallImage() ?? '',
                'thumbnail' => $child->getThumbnail() ?? '',
                'custom_attributes' => $this->getCustomAttributesArray($child, $configurableOptions),
                'store_id' => $parentStoreId,
                'store_ids' => $parentStoreIds,
            ];
        }

        return $childProductsArray;
    }

    /**
     * Batch stock (qty + is_in_stock) for a set of product ids — one query instead of
     * a getExtensionAttributes()->getStockItem() load per child.
     *
     * @param int[] $productIds
     * @return array<int, array{qty:float,is_in_stock:bool}>
     */
    private function getStockMap(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->from($connection->getTableName('cataloginventory_stock_item'), ['product_id', 'qty', 'is_in_stock'])
            ->where('product_id IN (?)', $productIds)
            ->where('stock_id = ?', 1);

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int)$row['product_id']] = [
                'qty' => (float)$row['qty'],
                'is_in_stock' => (bool)$row['is_in_stock'],
            ];
        }
        return $map;
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

            // Resolve select/multiselect labels via the per-run option-label cache
            // (getAllOptions once per attribute) instead of getAttributeText(), whose
            // getOptionText() hits the DB per option per child — thousands of round-trips
            // across a big configurable's children.
            if ($attribute->usesSource()) {
                $source = $this->safeGetSource($attribute);
                if ($source) {
                    $raw = (string)$value;
                    if ($raw !== '' && strpos($raw, ',') !== false) {
                        $labels = [];
                        foreach (explode(',', $raw) as $optionId) {
                            $label = $this->resolveOptionLabel($attributeCode, $source, trim($optionId));
                            if ($label !== '') {
                                $labels[] = $label;
                            }
                        }
                        $value = $labels;
                    } else {
                        $value = $this->resolveOptionLabel($attributeCode, $source, $value);
                    }
                }
            }

            if (!empty($value)) {
                $customAttributes[$attributeCode] = $value;
            }
        }
        return $customAttributes;
    }

    /**
     * Full swatch data for a set of attributes, set-based (one query), so the PDP can
     * render swatches from OpenSearch with no product SQL.
     *
     * @param int[] $attributeIds
     * @return array<int, array<int, array{type:int,value:string,label:string}>>
     */
    private function getSwatchOptions(array $attributeIds): array
    {
        $attributeIds = array_values(array_unique(array_filter(array_map('intval', $attributeIds))));
        if (!$attributeIds) {
            return [];
        }
        $connection = $this->productCollectionFactory->create()->getConnection();
        $optTable   = $connection->getTableName('eav_attribute_option');
        $valTable   = $connection->getTableName('eav_attribute_option_value');
        $swatchTable = $connection->getTableName('eav_attribute_option_swatch');

        $select = $connection->select()
            ->from(['o' => $optTable], ['attribute_id', 'option_id'])
            ->joinLeft(['v' => $valTable], 'v.option_id = o.option_id AND v.store_id = 0', ['label' => 'value'])
            ->joinLeft(['s' => $swatchTable], 's.option_id = o.option_id AND s.store_id = 0', ['type', 'swatch' => 'value'])
            ->where('o.attribute_id IN (?)', $attributeIds);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $attrId = (int)$row['attribute_id'];
            $optId  = (int)$row['option_id'];
            $result[$attrId][$optId] = [
                'type'  => $row['type'] !== null ? (int)$row['type'] : 0,
                'value' => (string)($row['swatch'] ?? ''),
                'label' => (string)($row['label'] ?? ''),
            ];
        }
        return $result;
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

    /**
     * Resolve an attribute's source model without failing when the source class
     * is missing or throws. Keeps indexing working on a base Magento install
     * regardless of any third-party source models left in the attribute config.
     *
     * @return \Magento\Eav\Model\Entity\Attribute\Source\SourceInterface|null
     */
    private function safeGetSource(AbstractAttribute $attribute)
    {
        try {
            if (!$attribute->usesSource()) {
                return null;
            }
            $source = $attribute->getSource();
            // Only a real source object is usable; some attribute configs resolve
            // to a scalar/string, which must not reach ->getOptionText()/->getAllOptions().
            return is_object($source) ? $source : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getAttributeOptions(AbstractAttribute $attribute): array
    {
        $options = [];
        $source = $this->safeGetSource($attribute);

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
