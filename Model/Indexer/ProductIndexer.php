<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Indexer;

use Psr\Log\LoggerInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagento\Model\Db\EntityLink;
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
    /** Keyword values under `attributes.*` longer than this are stored but not indexed as terms. */
    private const KEYWORD_IGNORE_ABOVE = 8191;

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
        private ProductRepositoryInterface $productRepository,
        private readonly EntityLink $entityLink,
        private ?\Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate = null,
        private ?\ParkkTech\FastMagento\Model\OpenSearch\IndexSettings $indexSettings = null,
    ) {
        $this->localeDate = $localeDate
            ?? \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\Stdlib\DateTime\TimezoneInterface::class);
        // Optional-with-fallback so an existing install's compiled DI keeps working across the
        // upgrade that introduces it (same pattern as $localeDate above).
        $this->indexSettings = $indexSettings
            ?? \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\ParkkTech\FastMagento\Model\OpenSearch\IndexSettings::class);
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
            // Commerce content staging exposes the version row as static attributes; they are
            // never facets and the document is keyed by entity id, not by version.
            'row_id', 'created_in', 'updated_in',
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
        $this->extensionProjection()->reset();
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
        $this->extensionProjection()->reset();
        if (empty($ids)) {
            // Stream entity ids set-based; never materialise full products just to list ids.
            $connection = $this->productCollectionFactory->create()->getConnection();
            $ids = $connection->fetchCol(
                $connection->select()
                    ->from($this->productCollectionFactory->create()->getMainTable(), ['entity_id'])
            );
        }
        // The heavy path is the batched/deterministic loader: set-based per chunk, no per-product
        // getById interceptor overhead, output explicitly defined (parity-verified against the old
        // per-product path). executePerProduct() below is retained as a reference/emergency path.
        $this->executeBatched(array_map('intval', $ids));
    }

    /**
     * Legacy per-product loader (getById + prepareDoc per product). Superseded by executeBatched()
     * and no longer called; kept as a reference and emergency fallback.
     */
    private function executePerProduct($ids): void
    {
        $indexName = $this->getIndexName();
        $client = $this->getSearchClient();
        $storeId = $this->getIndexStoreId();

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
        $this->extensionProjection()->reset();
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
        // Batched path: build extension_attributes deterministically from the chunk context —
        // the full stock-item row (+ product type, as the per-product stock item carries), the
        // configurable child links, and one empty object per assigned category (matching how the
        // per-product path's category_links serialise). The read path only reads these three keys.
        if ($this->batchCtx !== null) {
            $pid = (int) ($body['entity_id'] ?? 0);
            $stockFull = $this->batchCtx['stockFull'][$pid] ?? null;
            unset($body['extension_attributes']);
            if ($stockFull !== null) {
                $stockFull['type_id'] = $body['type_id'] ?? null;
                $body['extension_attributes']['stock_item'] = $stockFull;
                $childIds = (isset($body['type_id']) && $body['type_id'] === 'configurable')
                    ? ($this->batchCtx['childLinks'][$pid] ?? [])
                    : [];
                $links = [];
                foreach ($childIds as $cid) { $links[(string) $cid] = (string) $cid; }
                $body['extension_attributes']['configurable_product_links'] = $links;
                $catCount = (int) ($this->batchCtx['catLinkCount'][$pid] ?? 0);
                $body['extension_attributes']['category_links'] = $catCount > 0 ? array_fill(0, $catCount, []) : null;
            }
            return $body;
        }

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

    /**
     * Chunk size for the batched loader — how many products are loaded set-based per pass.
     */
    private const BATCH_LOAD_SIZE = 500;

    /**
     * Per-chunk batch context: every per-product helper query, done ONCE set-based for the whole
     * chunk and keyed by product id. Null outside a batched run — the shared prepareDoc()/
     * setExtensionAttributes() fall back to their per-product path (executeRow / warm-on-miss),
     * so single-product behaviour is unchanged.
     *
     * @var array<string, array<int, mixed>>|null
     */
    private ?array $batchCtx = null;

    /**
     * Fast, deterministic reindex (the default heavy path). Loads products SET-BASED per chunk via
     * a product collection instead of one productRepository->getById() per product, then builds a
     * doc whose contents are EXPLICITLY defined by this indexer — not incidentally by whichever
     * plugins happen to fire on a per-product load. Why this is the robust, scalable design:
     *  - The collection never goes through the per-id getById interceptor chain, so third-party
     *    per-product overhead (e.g. Webkul's marketplace_userdata on every load) never fires —
     *    a GENERAL win for any store with any getById-plugging module, not a per-extension hack.
     *  - Every field the doc needs (stock, salability, tier, catalog-rule, categories, request
     *    path, parents, websites) is reproduced from ONE batched query per chunk, so output does
     *    not depend on load-method quirks and stays identical across sites/extensions.
     *  - Products are still real Product objects carrying all EAV + custom attributes, so
     *    best-practice extensions (attributes / extension attributes) stay compatible. Extensions
     *    that inject computed data via per-load plugins are surfaced by the admin efficiency
     *    monitor rather than silently indexed.
     */
    public function executeBatched(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return;
        }
        $indexName = $this->getIndexName();
        $client = $this->getSearchClient();
        $storeId = $this->getIndexStoreId();

        foreach (array_chunk($ids, self::BATCH_LOAD_SIZE) as $chunk) {
            // Composite types (configurable / bundle / grouped) build their doc from the product
            // TYPE INSTANCE (super attributes, selections), whose EAV metadata depends on how the
            // parent was loaded — batching them can't be byte-identical. They are a tiny slice of a
            // catalog (~1%), so route them through the canonical per-product path (guaranteed
            // accurate) and reserve the fast batched path for the simple/virtual/downloadable
            // volume. Not a user setting — an internal correctness route.
            $types = $this->fetchProductTypes($chunk);
            $batchIds = [];
            $canonicalIds = [];
            foreach ($chunk as $id) {
                if (in_array($types[$id] ?? 'simple', ['configurable', 'bundle', 'grouped'], true)) {
                    $canonicalIds[] = $id;
                } else {
                    $batchIds[] = $id;
                }
            }

            $docs = [];
            $flush = function (array &$docs, bool $force = false) use ($client, $indexName) {
                if ($docs && ($force || count($docs) >= self::FLUSH_SIZE)) {
                    $this->bulkIndexNDJSON($client, $indexName, $docs);
                    $docs = [];
                }
            };

            // --- Canonical path FIRST: composite types (configurable / bundle / grouped) ---
            // Run these before the collection load so their getById fully primes the shared EAV
            // attribute cache; a collection's addAttributeToSelect('*') otherwise caches lean
            // attribute metadata that would then leak into getConfigurableAttributes() and change
            // the super-attribute serialisation the read path rebuilds.
            foreach ($canonicalIds as $id) {
                try {
                    $product = $this->productRepository->getById($id, false, $storeId, false);
                    if (!$product->getId()) {
                        continue;
                    }
                    $body = $this->prepareDoc($product);
                    $body = $this->setExtensionAttributes($body);
                    $docs[] = ['id' => (string) $product->getId(), 'body' => $body];
                } catch (\Throwable $e) {
                    $this->writeLog->writeErrorLog(
                        'Product ID: ' . $id . ' failed during batched indexing (composite): ' . $e->getMessage()
                    );
                    throw $e;
                }
                $flush($docs);
            }

            // --- Fast deterministic path: simple / virtual / downloadable ---
            if ($batchIds) {
                $collection = $this->productCollectionFactory->create();
                $collection->setStoreId($storeId)
                    ->addAttributeToSelect('*')
                    ->addFieldToFilter('entity_id', ['in' => $batchIds]);
                $collection->load();

                // One set-based query per data point for the whole chunk.
                $this->batchCtx = [
                    'stock'        => $this->getStockMap($batchIds),
                    'stockFull'    => $this->getStockFullMap($batchIds),
                    'categories'   => $this->batchCategoryNames($batchIds, $storeId),
                    'categoryIds'  => $this->batchCategoryIds($batchIds),
                    'mediaGallery' => $this->batchMediaGallery($batchIds, $storeId),
                    'requestPaths' => $this->batchRequestPaths($batchIds, $storeId),
                    'rule'         => $this->getRulePriceMapByGroup($batchIds),
                    'tier'         => $this->getChildTierPrices($batchIds),
                    'parents'      => $this->batchParentIds($batchIds),
                    'websites'     => $this->batchWebsiteIds($batchIds),
                    'childLinks'   => $this->batchChildLinks($batchIds),
                    'catLinkCount' => $this->batchCategoryLinkCounts($batchIds),
                    'reviews'      => $this->batchReviewSummary($batchIds, $storeId),
                    'links'        => $this->batchProductLinks($batchIds),
                    'bundleParents'=> $this->batchBundleParents($batchIds),
                ];
                foreach ($collection as $product) {
                    try {
                        $body = $this->prepareDoc($product);
                        $body = $this->setExtensionAttributes($body);
                        $docs[] = ['id' => (string) $product->getId(), 'body' => $body];
                    } catch (\Throwable $e) {
                        $this->writeLog->writeErrorLog(
                            'Product ID: ' . $product->getId() . ' failed during batched indexing: ' . $e->getMessage()
                        );
                        $this->batchCtx = null;
                        throw $e;
                    }
                    $flush($docs);
                }
                $this->batchCtx = null;
            }

            $flush($docs, true);
        }
    }

    /**
     * entity_id => type_id for a chunk, in one query — used to route composite types to the
     * canonical path.
     *
     * @param int[] $productIds
     * @return array<int, string>
     */
    private function fetchProductTypes(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->from($connection->getTableName('catalog_product_entity'), ['entity_id', 'type_id'])
            ->where('entity_id IN (?)', $productIds);
        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int) $row['entity_id']] = (string) $row['type_id'];
        }
        return $map;
    }

    /**
     * Configurable child product ids for one parent, straight from catalog_product_super_link —
     * the same set getUsedProducts() returns, without loading a single child product.
     *
     * @return int[]
     */
    private function getConfigurableChildIds(int $parentId): array
    {
        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->from(['sl' => $connection->getTableName('catalog_product_super_link')], ['product_id']);
        $parent = $this->entityLink->productEntityId($select, 'sl', 'parent_id');
        $select->where($parent . ' = ?', $parentId);
        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Super-attribute codes for one configurable parent (e.g. color, size), from
     * catalog_product_super_attribute eav_attribute, in configured position order — replaces
     * getConfigurableOptions(), which loads the full option + product-value structure.
     *
     * @return string[]
     */
    private function getConfigurableAttributeCodes(int $parentId): array
    {
        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->from(['sa' => $connection->getTableName('catalog_product_super_attribute')], [])
            ->join(
                ['ea' => $connection->getTableName('eav_attribute')],
                'ea.attribute_id = sa.attribute_id',
                ['attribute_code']
            );
        $parent = $this->entityLink->productEntityId($select, 'sa', 'product_id');
        $select->where($parent . ' = ?', $parentId)
            ->order('sa.position');
        return $connection->fetchCol($select);
    }

    /**
     * Full cataloginventory_stock_item rows for a chunk (all columns), keyed by product id — the
     * source for the doc's extension_attributes.stock_item, batched in one query. Uses a direct
     * select (not the stock-item collection, which needs a resource connection not always wired
     * in a bare CLI context) so it is robust everywhere.
     *
     * @param int[] $productIds
     * @return array<int, array<string, mixed>>
     */
    private function getStockFullMap(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->from($connection->getTableName('cataloginventory_stock_item'))
            ->where('product_id IN (?)', $productIds)
            ->where('stock_id = ?', 1);
        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int) $row['product_id']] = $row;
        }
        return $map;
    }

    /**
     * Category names per product for a chunk — one query joining category_product to the category
     * name attribute (store-scoped with default fallback), replacing getCategoryCollection() per
     * product.
     *
     * @param int[] $productIds
     * @return array<int, string[]>
     */
    private function batchCategoryNames(array $productIds, int $storeId): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->productCollectionFactory->create()->getConnection();
        $nameAttrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('eav_attribute'), ['attribute_id'])
                ->where('entity_type_id = (SELECT entity_type_id FROM ' . $connection->getTableName('eav_entity_type')
                    . " WHERE entity_type_code = 'catalog_category')")
                ->where('attribute_code = ?', 'name')
        );
        $cpt = $connection->getTableName('catalog_category_product');
        $cev = $connection->getTableName('catalog_category_entity_varchar');
        $select = $connection->select()
            ->from(['cp' => $cpt], ['product_id']);
        // Commerce: the varchar table keys on catalog_category_entity.row_id, so reach the
        // category row via the entity table first. Open Source: no join, the same SQL as before.
        $link = $this->entityLink->categoryLinkField();
        if ($this->entityLink->isCategoryStaged()) {
            $select->join(
                ['ce' => $connection->getTableName('catalog_category_entity')],
                'ce.entity_id = cp.category_id',
                []
            );
            $catRef = 'ce.' . $link;
        } else {
            $catRef = 'cp.category_id';
        }
        $select
            ->joinLeft(
                ['d' => $cev],
                "d.{$link} = {$catRef} AND d.attribute_id = {$nameAttrId} AND d.store_id = 0",
                []
            )
            ->joinLeft(
                ['s' => $cev],
                "s.{$link} = {$catRef} AND s.attribute_id = {$nameAttrId} AND s.store_id = {$storeId}",
                []
            )
            ->columns(['name' => new \Zend_Db_Expr('IFNULL(s.value, d.value)')])
            ->where('cp.product_id IN (?)', $productIds)
            ->order('cp.product_id')
            ->order('cp.category_id');
        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            if ($row['name'] !== null) {
                $map[(int) $row['product_id']][] = (string) $row['name'];
            }
        }
        return $map;
    }

    /**
     * Canonical request_path per product for a chunk — one url_rewrite query instead of one per
     * product. Mirrors getProductRequestPath() (metadata IS NULL, redirect_type 0, first row).
     *
     * @param int[] $productIds
     * @return array<int, string>
     */
    private function batchRequestPaths(array $productIds, int $storeId): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->from($connection->getTableName('url_rewrite'), ['entity_id', 'request_path', 'url_rewrite_id'])
            ->where('entity_type = ?', 'product')
            ->where('entity_id IN (?)', $productIds)
            ->where('store_id = ?', $storeId)
            ->where('metadata IS NULL')
            ->where('redirect_type = ?', 0)
            ->order('url_rewrite_id ASC');
        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $pid = (int) $row['entity_id'];
            if (!isset($map[$pid]) && $row['request_path'] !== '' && $row['request_path'] !== null) {
                $map[$pid] = (string) $row['request_path'];   // first (lowest url_rewrite_id) wins
            }
        }
        return $map;
    }

    /**
     * Parent configurable ids per simple-product id for a chunk — one query instead of
     * getParentIdsByChild() per product.
     *
     * @param int[] $productIds
     * @return array<int, int[]>
     */

    /** Empty link graph, used when a product has no links at all. */
    private const EMPTY_LINKS = ['related' => [], 'upsell' => [], 'crosssell' => []];

    /** Magento link_type_id => the key used on the indexed document. */
    private const LINK_TYPES = [
        \Magento\Catalog\Model\Product\Link::LINK_TYPE_RELATED => 'related',
        \Magento\Catalog\Model\Product\Link::LINK_TYPE_UPSELL => 'upsell',
        \Magento\Catalog\Model\Product\Link::LINK_TYPE_CROSSSELL => 'crosssell',
    ];

    /**
     * Related / up-sell / cross-sell ids for a whole chunk in ONE query, in merchant position
     * order (the order the storefront blocks render them in).
     *
     * Position lives in catalog_product_link_attribute_int, addressed through the per-link-type
     * attribute row in catalog_product_link_attribute, so the ordering join is the same shape the
     * native link collection uses. Products with no links are simply absent from the map and fall
     * back to self::EMPTY_LINKS.
     *
     * @param int[] $productIds
     * @return array<int, array{related: int[], upsell: int[], crosssell: int[]}>
     */

    /**
     * Bundle parent ids per product for a chunk, in one query.
     *
     * Mirrors Magento\Bundle\Model\ResourceModel\Selection::getParentIdsByChild — joining the
     * entity table so the ids returned are entity_ids, matching what the observer expects.
     *
     * @param int[] $productIds
     * @return array<int, int[]>
     */
    private function batchBundleParents(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->distinct(true)
            ->from(['s' => $connection->getTableName('catalog_product_bundle_selection')], ['product_id'])
            ->join(
                ['e' => $connection->getTableName('catalog_product_entity')],
                $this->entityLink->productChildJoin('e', 's', 'parent_product_id'),
                ['parent_product_id' => 'e.entity_id']
            )
            ->where('s.product_id IN (?)', $productIds);

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int) $row['product_id']][] = (int) $row['parent_product_id'];
        }

        return $map;
    }

    private function batchProductLinks(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->from(
                ['l' => $connection->getTableName('catalog_product_link')],
                ['linked_product_id', 'link_type_id', 'link_id']
            );
        // l.product_id is a row_id on Commerce; resolve it to the parent's entity id.
        $parent = $this->entityLink->productEntityId($select, 'l', 'product_id');
        $select
            ->columns(['product_id' => new \Zend_Db_Expr($parent)])
            ->joinLeft(
                ['la' => $connection->getTableName('catalog_product_link_attribute')],
                'la.link_type_id = l.link_type_id AND la.product_link_attribute_code = '
                    . $connection->quote('position'),
                []
            )
            ->joinLeft(
                ['ai' => $connection->getTableName('catalog_product_link_attribute_int')],
                'ai.product_link_attribute_id = la.product_link_attribute_id AND ai.link_id = l.link_id',
                ['position' => 'ai.value']
            )
            ->where($parent . ' IN (?)', $productIds)
            ->where('l.link_type_id IN (?)', array_keys(self::LINK_TYPES))
            ->order('position ' . \Magento\Framework\DB\Select::SQL_ASC)
            ->order('l.link_id ' . \Magento\Framework\DB\Select::SQL_ASC);

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $key = self::LINK_TYPES[(int) $row['link_type_id']] ?? null;
            if ($key === null) {
                continue;
            }
            $parent = (int) $row['product_id'];
            if (!isset($map[$parent])) {
                $map[$parent] = self::EMPTY_LINKS;
            }
            $map[$parent][$key][] = (int) $row['linked_product_id'];
        }

        return $map;
    }

    /**
     * Single-product form of batchProductLinks(), for the canonical (composite-type) index path
     * where no batch context exists.
     *
     * @return array{related: int[], upsell: int[], crosssell: int[]}
     */
    private function getProductLinks(int $productId): array
    {
        return $this->batchProductLinks([$productId])[$productId] ?? self::EMPTY_LINKS;
    }

    private function batchParentIds(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->from(['sl' => $connection->getTableName('catalog_product_super_link')], ['product_id']);
        // sl.parent_id is a row_id on Commerce; the doc must carry the parent's entity id.
        $parent = $this->entityLink->productEntityId($select, 'sl', 'parent_id');
        $select
            ->columns(['parent_id' => new \Zend_Db_Expr($parent)])
            ->where('sl.product_id IN (?)', $productIds);
        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int) $row['product_id']][] = (string) $row['parent_id'];
        }
        return $map;
    }

    /**
     * Website ids per product for a chunk — one query instead of getWebsiteIds() per product.
     *
     * @param int[] $productIds
     * @return array<int, int[]>
     */
    private function batchWebsiteIds(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->from($connection->getTableName('catalog_product_website'), ['product_id', 'website_id'])
            ->where('product_id IN (?)', $productIds);
        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int) $row['product_id']][] = (string) $row['website_id'];
        }
        return $map;
    }

    /**
     * Configurable child product ids per PARENT id for a chunk (extension_attributes
     * .configurable_product_links) — one super_link query instead of a type-instance call per
     * configurable.
     *
     * @param int[] $parentIds
     * @return array<int, int[]>
     */
    private function batchChildLinks(array $parentIds): array
    {
        if (!$parentIds) {
            return [];
        }
        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->from(['sl' => $connection->getTableName('catalog_product_super_link')], []);
        $parent = $this->entityLink->productEntityId($select, 'sl', 'parent_id');
        $select
            ->columns(['parent_id' => new \Zend_Db_Expr($parent), 'product_id' => 'sl.product_id'])
            ->where($parent . ' IN (?)', $parentIds)
            ->order('sl.product_id');
        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int) $row['parent_id']][] = (int) $row['product_id'];
        }
        return $map;
    }

    /**
     * Number of category assignments per product for a chunk. The per-product path's
     * extension_attributes.category_links serialise to one empty object per assigned category
     * ([[], [], …]); we reproduce that shape deterministically from this count.
     *
     * @param int[] $productIds
     * @return array<int, int>
     */
    private function batchCategoryLinkCounts(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->from($connection->getTableName('catalog_category_product'), ['product_id', 'c' => new \Zend_Db_Expr('COUNT(*)')])
            ->where('product_id IN (?)', $productIds)
            ->group('product_id');
        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int) $row['product_id']] = (int) $row['c'];
        }
        return $map;
    }

    /**
     * Category ids per product for a chunk (doc category_ids, emitted as strings to match the
     * per-product path) — one catalog_category_product query.
     *
     * @param int[] $productIds
     * @return array<int, string[]>
     */
    private function batchCategoryIds(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->from($connection->getTableName('catalog_category_product'), ['product_id', 'category_id'])
            ->where('product_id IN (?)', $productIds)
            ->order('category_id');
        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int) $row['product_id']][] = (string) $row['category_id'];
        }
        return $map;
    }

    /**
     * Product media gallery per product for a chunk, in the exact shape the per-product Gallery
     * ReadHandler produces ({images: {value_id: {...store/default-merged...}}, values: []}) — one
     * three-table join for the whole chunk instead of a per-product gallery load. Store values
     * override defaults (store 0); label/position/disabled carry both the resolved and _default.
     *
     * @param int[] $productIds
     * @return array<int, array<string, mixed>>
     */
    /**
     * Single-product review summary, for the per-product indexing path (composite types).
     *
     * @return array{reviews_count: int, rating_summary: int}
     */
    private function getReviewSummaryFor(int $productId, int $storeId): array
    {
        $none = ['reviews_count' => 0, 'rating_summary' => 0];
        if ($productId <= 0) {
            return $none;
        }
        return $this->batchReviewSummary([$productId], $storeId)[$productId] ?? $none;
    }

    /**
     * reviews_count + rating_summary for a whole chunk in one query.
     *
     * Store-scoped: review_entity_summary holds a row per (product, store), and entity_type 1 is
     * the product entity in Magento's review schema.
     *
     * @param int[] $productIds
     * @return array<int, array{reviews_count: int, rating_summary: int}>
     */
    private function batchReviewSummary(array $productIds, int $storeId): array
    {
        if (!$productIds) {
            return [];
        }
        try {
            $connection = $this->productCollectionFactory->create()->getConnection();
            $select = $connection->select()
                ->from(
                    $connection->getTableName('review_entity_summary'),
                    ['entity_pk_value', 'reviews_count', 'rating_summary']
                )
                ->where('entity_pk_value IN (?)', $productIds)
                ->where('store_id = ?', $storeId)
                ->where('entity_type = ?', 1);

            $out = [];
            foreach ($connection->fetchAll($select) as $row) {
                $out[(int) $row['entity_pk_value']] = [
                    'reviews_count' => (int) $row['reviews_count'],
                    'rating_summary' => (int) $row['rating_summary'],
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            // Reviews are decorative; never fail an index build over them.
            $this->logger->warning('[FastMagento] review summary batch failed: ' . $e->getMessage());
            return [];
        }
    }

    private function batchMediaGallery(array $productIds, int $storeId): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->productCollectionFactory->create()->getConnection();
        $attrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('eav_attribute'), ['attribute_id'])
                ->where('entity_type_id = (SELECT entity_type_id FROM ' . $connection->getTableName('eav_entity_type')
                    . " WHERE entity_type_code = 'catalog_product')")
                ->where('attribute_code = ?', 'media_gallery')
        );
        $mg  = $connection->getTableName('catalog_product_entity_media_gallery');
        $vte = $connection->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $val = $connection->getTableName('catalog_product_entity_media_gallery_value');
        // value_to_entity / value key on row_id on Commerce; vte<->d/s match on the link field and
        // the product id column/filter go through the entity id expression (a join only on EE).
        $link = $this->entityLink->productLinkField();
        $select = $connection->select()
            ->from(['mg' => $mg], ['value_id', 'file' => 'value', 'media_type'])
            ->join(['vte' => $vte], 'vte.value_id = mg.value_id', []);
        $pid = $this->entityLink->productEntityId($select, 'vte');
        $select
            ->columns(['entity_id' => new \Zend_Db_Expr($pid)])
            ->joinLeft(['d' => $val], "d.value_id = mg.value_id AND d.{$link} = vte.{$link} AND d.store_id = 0",
                ['label_default' => 'label', 'position_default' => 'position', 'disabled_default' => 'disabled'])
            ->joinLeft(
                ['s' => $val],
                "s.value_id = mg.value_id AND s.{$link} = vte.{$link} AND s.store_id = " . (int) $storeId,
                ['s_label' => 'label', 's_position' => 'position', 's_disabled' => 'disabled']
            )
            ->where($pid . ' IN (?)', $productIds)
            ->where('mg.attribute_id = ?', $attrId)
            ->order($pid)
            ->order(new \Zend_Db_Expr('IFNULL(d.position, 0)'))
            ->order('mg.value_id');

        $str = static fn ($v) => $v === null ? null : (string) $v;
        $images = [];
        foreach ($connection->fetchAll($select) as $r) {
            $pid = (int) $r['entity_id'];
            $vid = (string) $r['value_id'];
            $label    = $r['s_label']    !== null ? $r['s_label']    : $r['label_default'];
            $position = $r['s_position'] !== null ? $r['s_position'] : $r['position_default'];
            $disabled = $r['s_disabled'] !== null ? $r['s_disabled'] : $r['disabled_default'];
            $images[$pid][$vid] = [
                'value_id'                  => $vid,
                'file'                      => (string) $r['file'],
                'media_type'                => (string) $r['media_type'],
                'entity_id'                 => (string) $r['entity_id'],
                'label'                     => $str($label),
                'position'                  => $str($position),
                'disabled'                  => $str($disabled),
                'label_default'             => $str($r['label_default']),
                'position_default'          => $str($r['position_default']),
                'disabled_default'          => $str($r['disabled_default']),
                'video_provider'            => null,
                'video_url'                 => null,
                'video_title'               => null,
                'video_description'         => null,
                'video_metadata'            => null,
                'video_provider_default'    => null,
                'video_url_default'         => null,
                'video_title_default'       => null,
                'video_description_default' => null,
                'video_metadata_default'    => null,
            ];
        }
        $out = [];
        foreach ($images as $pid => $imgs) {
            $out[$pid] = ['images' => $imgs, 'values' => []];
        }
        return $out;
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
    /**
     * Make everything written so far immediately searchable.
     *
     * OpenSearch writes are durable at once but only become visible to SEARCH at the next refresh
     * — one second by default. Most FastMagento product reads are realtime GET/mget
     * (OpenSearchPdpFetcher), so a product page and a category listing already show an admin edit
     * on the very next request without this. The SEARCH paths do not: InstantSearch and the search
     * results collection both query, so after an instant update they kept returning the previous
     * document — measured directly, a realtime GET returned the new short_description while a
     * search on the same id still returned the old one, or no hit at all.
     *
     * Called only from the instant single-product path, never from a full reindex, where forcing a
     * refresh per batch would work against the bulk write.
     */
    public function refreshIndex(): void
    {
        try {
            $this->getSearchClient()->getOpenSearchClient()->indices()->refresh(
                ['index' => $this->getIndexName()]
            );
        } catch (\Throwable $e) {
            // A missed refresh only costs a second of search staleness; never surface it.
            $this->writeLog->writeErrorLog(
                '[FastMagento] product index refresh failed: ' . $e->getMessage()
            );
        }
    }

    private function bulkIndexNDJSON($client, string $indexName, array $docs): void
    {
        $writer = \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\ParkkTech\FastMagento\Model\OpenSearch\BoundedBulkWriter::class);
        $writer->write($client->getOpenSearchClient(), $indexName, $docs);
    }

    private function summarizeBulkErrors(array $response): array
    {
        $failed = 0;
        $reasons = [];

        foreach ($response['items'] ?? [] as $item) {
            $action = reset($item);
            if (!is_array($action) || !isset($action['error'])) {
                continue;
            }
            $failed++;
            $reason = is_array($action['error'])
                ? (string) ($action['error']['reason'] ?? $action['error']['type'] ?? 'unknown error')
                : (string) $action['error'];

            // Collapse the id-specific tail so N identical failures report as one reason.
            $reasons[$reason] = true;
        }

        $distinct = array_slice(array_keys($reasons), 0, 3);
        $suffix = count($reasons) > 3 ? sprintf(' (+%d more)', count($reasons) - 3) : '';

        $hint = '';
        foreach ($reasons as $reason => $_) {
            if (stripos($reason, 'total fields') !== false) {
                $hint = ' — raise Stores > Configuration > FastMagento > Indexing >'
                    . ' "OpenSearch field limit", then reindex.';
                break;
            }
        }

        return [$failed, implode('; ', $distinct) . $suffix . $hint];
    }

    private function buildDynamicMapping(): array
    {
        // total_fields.limit is applied here rather than left to the cluster default: the
        // `attributes` object below is `dynamic: true`, so an attribute-heavy catalog will map
        // past OpenSearch's default 1000 and the offending docs get rejected per-item during bulk.
        return $this->indexSettings->applyTo([
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
                    ],
                    // Canonical request path, mapped as a keyword so the URL-rewrite router can
                    // resolve a product URL with one term query (CategoryUrlFinderPlugin).
                    'request_path' => ['type' => 'keyword', 'ignore_above' => 1024],
                    'fm_extension_attributes' => ['type' => 'object', 'enabled' => false],
                    // Related / up-sell / cross-sell id lists, in merchant position order.
                    // Mapped explicitly because the index is `dynamic: false`; the read path only
                    // ever reads them back out of _source, never queries them, so `index: false`
                    // keeps them out of the inverted index while still being returned by mget.
                    'fm_links' => [
                        'type' => 'object',
                        'properties' => [
                            'related' => ['type' => 'integer', 'index' => false],
                            'upsell' => ['type' => 'integer', 'index' => false],
                            'crosssell' => ['type' => 'integer', 'index' => false],
                        ]
                    ],
                    // Bundle parents of this product (catalog_product_bundle_selection).
                    // Magento_Bundle's up-sell observer asks for these on EVERY product page,
                    // including the overwhelming majority that belong to no bundle at all.
                    'fm_bundle_parents' => ['type' => 'integer', 'index' => false]
                ]
            ]
        ]);
    }


    private function isDefaultMagentoAttribute(string $attributeCode): bool
    {
        static $defaultAttributes = null;

        // Load default Magento attributes once (optimization)
        if ($defaultAttributes === null) {
            $defaultAttributes = $this->getDefaultMagentoAttributes();
        }

        return in_array($attributeCode, $defaultAttributes, true);
    }

    private function getDynamicAttributeMapping(): array
    {
        $mapping = [];

        // Use SearchCriteriaBuilder to fetch attributes properly
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $allAttributes = $this->attributeRepository->getList('catalog_product', $searchCriteria);

        foreach ($allAttributes->getItems() as $attribute) {
            $attributeCode = $attribute->getAttributeCode();

            // Ensure only custom attributes are indexed
            if ($this->isDefaultMagentoAttribute($attributeCode)) {
                continue;
            }

            $mapping[$attributeCode] = $this->attributeFieldMapping($attribute);
        }

        return $mapping;
    }

    /**
     * How one custom attribute is mapped under `attributes.*`.
     *
     * Option and short-value attributes are keywords: that is what filtering, facets and exact
     * matching need. Free text — textarea, WYSIWYG (texteditor), Page Builder — is not a term and
     * must not be mapped as one: Lucene refuses any single term over 32766 bytes, so a size chart
     * or care-instructions HTML of that length rejected the whole document ("Document contains at
     * least one immense term in field=attributes.<code>"). Those are analysed text instead, which
     * has no such limit and is still searchable. The keyword branch carries `ignore_above` as a
     * second guard: a value longer than that is kept in _source but not indexed as a term, so an
     * over-long value in an attribute that is nominally a select can never reject a document.
     *
     * @return array<string, mixed>
     */
    private function attributeFieldMapping(\Magento\Eav\Api\Data\AttributeInterface $attribute): array
    {
        $input = (string) $attribute->getFrontendInput();
        $backend = (string) $attribute->getBackendType();
        $freeText = in_array($input, ['textarea', 'texteditor', 'pagebuilder'], true)
            || ($backend === 'text' && $input !== 'multiselect' && $input !== 'select');
        if ($freeText) {
            return ['type' => 'text'];
        }

        return ['type' => 'keyword', 'ignore_above' => self::KEYWORD_IGNORE_ABOVE];
    }


    private function extensionProjection(): \ParkkTech\FastMagento\Model\Projection\ExtensionAttributes
    {
        return \Magento\Framework\App\ObjectManager::getInstance()->get(\ParkkTech\FastMagento\Model\Projection\ExtensionAttributes::class);
    }

    private function prepareDoc(\Magento\Catalog\Model\Product $product): array
    {
        $productData = $product->getData();
        $productData['fm_extension_attributes'] = $this->extensionProjection()->project($product);
        // Drop the product type's RUNTIME object caches (`_cache_instance_configurable_attributes`,
        // `_cache_instance_used_product_attributes`, …). getData() carries them once the type
        // instance has run, and serializing them into the index bloats the doc AND, served back on
        // an OS shell, makes core treat stale JSON arrays as attribute objects (cart-page 500 on
        // getUsedProductAttributes getId()). They are always rebuilt at runtime; never index them.
        foreach (array_keys($productData) as $dataKey) {
            if (strncmp((string) $dataKey, '_cache_instance', 15) === 0) {
                unset($productData[$dataKey]);
            }
        }
        $productData['created_at'] = $this->formatDateForOpenSearch($product->getCreatedAt());
        $productData['updated_at'] = $this->formatDateForOpenSearch($product->getUpdatedAt());
        $pid = (int) $product->getId();
        $productData['category_names'] = $this->batchCtx !== null
            ? ($this->batchCtx['categories'][$pid] ?? [])
            : $this->getCategoryNames($product);
        // Canonical product request_path (from url_rewrite) so the shell serves grid/list URLs
        // without falling into the dynamic url_rewrite storage — the N+1 that made a page of
        // OS-served product cards explode into thousands of url_rewrite lookups.
        $productData['request_path'] = $this->batchCtx !== null
            ? ($this->batchCtx['requestPaths'][$pid] ?? null)
            : $this->getProductRequestPath($pid);
        // Related / up-sell / cross-sell id lists, resolved at INDEX time.
        // The link graph lives only in catalog_product_link, so LinkProductCollectionPlugin used
        // to read it from MySQL on every product page even when the linked products themselves
        // were served from OpenSearch — three catalogue queries per PDP that survived the whole
        // serving layer. Projecting the graph onto the parent document lets the read path take
        // the ids straight off the doc it has already fetched. Falls back to the DB when absent,
        // so an index written before this field existed keeps working until the next reindex.
        $productData['fm_links'] = $this->batchCtx !== null
            ? ($this->batchCtx['links'][$pid] ?? self::EMPTY_LINKS)
            : $this->getProductLinks($pid);
        // Bundle parents, same rationale as fm_links: an empty list is itself the useful answer,
        // because it lets the read path skip the query instead of running it to learn "none".
        $productData['fm_bundle_parents'] = $this->batchCtx !== null
            ? ($this->batchCtx['bundleParents'][$pid] ?? [])
            : $this->batchBundleParents([$pid])[$pid] ?? [];

        if ($this->batchCtx !== null) {
            // Deterministic stock: reproduce every field the per-product getById path gets from
            // its stock plugin (is_in_stock, qty, quantity_and_stock_status, is_salable) from the
            // one batched stock query, so the doc is identical regardless of load method.
            $st = $this->batchCtx['stock'][$pid] ?? ['qty' => 0.0, 'is_in_stock' => false];
            $productData['is_in_stock'] = (bool) $st['is_in_stock'];
            $productData['stock_data'] = ['qty' => (float) $st['qty']];
            $productData['quantity_and_stock_status'] = [
                'is_in_stock' => (bool) $st['is_in_stock'],
                'qty' => (float) $st['qty'],
            ];
            $productData['is_salable'] = ($st['is_in_stock'] && (float) $st['qty'] > 0) ? 1 : 0;
            $productData['category_ids'] = $this->batchCtx['categoryIds'][$pid] ?? [];
            $productData['media_gallery'] = $this->batchCtx['mediaGallery'][$pid] ?? ['images' => [], 'values' => []];
        } else {
            $productData['is_in_stock'] = (bool)$product->getExtensionAttributes()?->getStockItem()?->getIsInStock();
            $productData['stock_data'] = [
                'qty' => (float)$product->getExtensionAttributes()?->getStockItem()?->getQty()
            ];
        }

        // Review stars are rendered on every product card, and Magento resolves them one product
        // at a time (Review\Model\ReviewSummary::appendSummaryDataToObject), so a listing pays one
        // review_entity_summary query per card. Served back by Plugin\Review\ReviewSummaryPlugin.
        //
        // Deliberately OUTSIDE the batched/non-batched branches above: composite products are
        // routed through the per-product path, so projecting this only in the batched branch left
        // every configurable without review data — the exact products a listing is usually made of.
        // A product with no reviews is written as 0/0 rather than omitted, so the read path can
        // tell "no reviews" from "not indexed" and only falls back to SQL for the latter.
        $rev = $this->batchCtx !== null
            ? ($this->batchCtx['reviews'][$pid] ?? ['reviews_count' => 0, 'rating_summary' => 0])
            : $this->getReviewSummaryFor($pid, (int) $product->getStoreId());
        $productData['reviews_count'] = (int) $rev['reviews_count'];
        $productData['rating_summary'] = (int) $rev['rating_summary'];

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
        // Add Tier Prices from MySQL to OpenSearch
        $productData['tier_prices'] = $this->batchCtx !== null
            ? ($this->batchCtx['tier'][$pid] ?? [])
            : $this->getTierPrices($product);

        // Add Catalog Rule Prices from MySQL to OpenSearch, PER CUSTOMER GROUP.
        // Catalog rules are group-specific (a Wholesale rule prices differently than a guest
        // rule), so the doc carries a {group_id => rule_price} map and the read path resolves
        // the current customer group. `catalog_rule_price` is kept as the group-0 scalar for
        // backward compatibility with any code still reading it.
        $parentRuleMap = $this->batchCtx !== null
            ? ($this->batchCtx['rule'][$pid] ?? [])
            : ($this->getRulePriceMapByGroup([$pid])[$pid] ?? []);
        $productData['catalog_rule_prices'] = $parentRuleMap;
        $productData['catalog_rule_price'] = isset($parentRuleMap[0])
            ? ['rule_price' => (float)$parentRuleMap[0]]
            : [];

        // Add Parent IDs for Simple Products (If Configurable)
        if ($product->getTypeId() === 'simple') {
            $productData['parent_ids'] = $this->batchCtx !== null
                ? ($this->batchCtx['parents'][$pid] ?? [])
                : $this->getParentIds($product);
            // Rule-neutral base (see getBaseFinalPrice) so a StockSyncer reprojection triggered
            // inside a frontend request doesn't bake the shopper's catalog-rule price into the doc.
            $productData['final_price'] = $this->getBaseFinalPrice($product);
        }

        // Downloadable: index full link/sample data so the native PDP blocks render
        // straight from OpenSearch (title/price/sample), no downloadable SQL on read.
        if ($product->getTypeId() === 'downloadable') {
            $productData['downloadable_links'] = $this->getDownloadableLinks($product);
            $productData['downloadable_samples'] = $this->getDownloadableSamples($product);
        }

        $productData['website_ids'] = $this->batchCtx !== null
            ? ($this->batchCtx['websites'][$pid] ?? [])
            : $product->getWebsiteIds();
        $productData['store_id'] = $product->getStoreId();
        $productData['store_ids'] = $product->getStoreIds();

        return $productData;
    }

    /**
     * Serialize a downloadable product's links to plain arrays (the raw type-instance
     * Link objects json-encode to {}), store/website-resolved title + price like the
     * native block. Read back into Link models by ShellProductBuilder.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDownloadableLinks(\Magento\Catalog\Model\Product $product): array
    {
        $out = [];
        try {
            foreach ($product->getTypeInstance()->getLinks($product) as $link) {
                $out[] = [
                    'link_id' => (int) $link->getId(),
                    'title' => (string) $link->getTitle(),
                    'price' => (float) $link->getPrice(),
                    'sort_order' => (int) $link->getSortOrder(),
                    'is_shareable' => (int) $link->getIsShareable(),
                    'number_of_downloads' => (int) $link->getNumberOfDownloads(),
                    'link_type' => (string) $link->getLinkType(),
                    'link_url' => (string) $link->getLinkUrl(),
                    'link_file' => (string) $link->getLinkFile(),
                    'sample_type' => (string) $link->getSampleType(),
                    'sample_url' => (string) $link->getSampleUrl(),
                    'sample_file' => (string) $link->getSampleFile(),
                ];
            }
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] downloadable links index failed for ' . $product->getId() . ': ' . $e->getMessage()
            );
        }
        return $out;
    }

    /**
     * Serialize a downloadable product's samples to plain arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDownloadableSamples(\Magento\Catalog\Model\Product $product): array
    {
        $out = [];
        try {
            foreach ($product->getTypeInstance()->getSamples($product) as $sample) {
                $out[] = [
                    'sample_id' => (int) $sample->getId(),
                    'title' => (string) $sample->getTitle(),
                    'sort_order' => (int) $sample->getSortOrder(),
                    'sample_type' => (string) $sample->getSampleType(),
                    'sample_url' => (string) $sample->getSampleUrl(),
                    'sample_file' => (string) $sample->getSampleFile(),
                ];
            }
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] downloadable samples index failed for ' . $product->getId() . ': ' . $e->getMessage()
            );
        }
        return $out;
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

            // Convert boolean values to "Yes" or "No"
            if (is_bool($value)) {
                $value = [$value ? 'Yes' : 'No'];
            }

            // Handle dropdown or multi-select attributes (skip when the source model is unavailable)
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

            // Ensure all values are stored as arrays
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

    /**
     * Canonical (category-independent) request_path for a product in the served store view.
     * `metadata IS NULL` excludes the per-category rewrites; `redirect_type = 0` excludes 301/302
     * rows. Returns null when no rewrite exists (shell then falls back to url_path).
     */
    private function getProductRequestPath(int $productId): ?string
    {
        if ($productId <= 0) {
            return null;
        }
        try {
            $connection = $this->productCollectionFactory->create()->getConnection();
            $select = $connection->select()
                ->from($connection->getTableName('url_rewrite'), ['request_path'])
                ->where('entity_type = ?', 'product')
                ->where('entity_id = ?', $productId)
                ->where('store_id = ?', $this->getIndexStoreId())
                ->where('metadata IS NULL')
                ->where('redirect_type = ?', 0)
                ->order('url_rewrite_id ASC')
                ->limit(1);
            $path = $connection->fetchOne($select);
            return ($path !== false && $path !== '') ? (string)$path : null;
        } catch (\Throwable $e) {
            return null;
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
     * Fetch Tier Prices from `catalog_product_entity_tier_price`
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
     * Fetch Parent Configurable Product IDs Using Proper DI
     */
    private function getParentIds(\Magento\Catalog\Model\Product $product): array
    {
        return $this->configurableResource->getParentIdsByChild($product->getId());
    }

    /**
     * Rule-neutral base final price: the special price when it is active and lower than the
     * regular price, otherwise the regular price. Deliberately does NOT call getFinalPrice()
     * (which dispatches catalog_product_get_final_price the per-product catalog-rule
     * getRulePrice() query) nor apply catalog rules — those are stored per group in
     * catalog_rule_prices and applied by the shell at read time. Tier prices are indexed
     * separately and don't affect the qty-1 base shown on a product doc.
     */
    private function getBaseFinalPrice(\Magento\Catalog\Model\Product $product): float
    {
        $price = (float) $product->getPrice();
        $special = $product->getSpecialPrice();

        if ($special === null || $special === false || (float) $special <= 0.0 || (float) $special >= $price) {
            return $price;
        }

        if ($this->isSpecialPriceActive($product)) {
            return (float) $special;
        }

        return $price;
    }

    /**
     * Whether a product's special price is within its (optional) from/to date window in the
     * store timezone. Empty dates mean always active — matching core _applySpecialPrice.
     */
    private function isSpecialPriceActive(\Magento\Catalog\Model\Product $product): bool
    {
        try {
            return $this->localeDate->isScopeDateInInterval(
                $product->getStoreId(),
                $product->getSpecialFromDate() ?: null,
                $product->getSpecialToDate() ?: null
            );
        } catch (\Throwable $e) {
            // On any date-parsing quirk, treat the special price as active (it is set + lower).
            return true;
        }
    }


    public function getChildProducts(\Magento\Catalog\Model\Product $product)
    {
        $childProductsArray = [];
        $configurableOptions = [];

        $childIds = [];
        switch ($product->getTypeId()) {
            case Configurable::TYPE_CODE:
                // Child ids + super-attribute codes come straight from the link / super-attribute
                // tables. getUsedProducts() and getConfigurableOptions() would each FULLY LOAD
                // every child (hundreds–thousands, with per-child MSI/EAV) just to yield the ids
                // and codes we re-load set-based below — the dominant cost of indexing a big
                // configurable. The id set is identical to getUsedProducts() (verified).
                $childIds = $this->getConfigurableChildIds((int) $product->getId());
                $configurableOptions = $this->getConfigurableAttributeCodes((int) $product->getId());
                break;

            case Grouped::TYPE_CODE:
                foreach ($product->getTypeInstance()->getAssociatedProducts($product) as $child) {
                    $cid = (int) $child->getId();
                    if ($cid) {
                        $childIds[$cid] = $cid;
                    }
                }
                $childIds = array_values($childIds);
                break;

            case BundleType::TYPE_CODE:
                $selectionCollection = $product->getTypeInstance()->getSelectionsCollection(
                    $product->getTypeInstance()->getOptionsIds($product),
                    $product
                );
                foreach ($selectionCollection as $child) {
                    $cid = (int) $child->getId();
                    if ($cid) {
                        $childIds[$cid] = $cid;
                    }
                }
                $childIds = array_values($childIds);
                break;
        }

        if (!$childIds) {
            return $childProductsArray;
        }

        // ONE set-based collection load for ALL children (replaces a full ->load() per
        // child — the N+1 that made a 660-child configurable take ~60s to project). Select only
        // the columns the child record actually reads (display + price + stock + super-attributes)
        // instead of every EAV attribute — a much lighter join across hundreds of children.
        $childAttrs = array_merge(
            ['sku', 'name', 'price', 'special_price', 'special_from_date', 'special_to_date',
             'image', 'small_image', 'thumbnail', 'status'],
            $configurableOptions
        );
        $childCollection = $this->productCollectionFactory->create();
        $childCollection->addAttributeToSelect($childAttrs)
            ->addFieldToFilter('entity_id', ['in' => $childIds]);
        $childCollection->load();

        // ONE batch stock query for all children (replaces getStockItem() per child).
        $stockMap = $this->getStockMap($childIds);
        // ONE batch catalog-rule-price + tier-price query for ALL children, so each child
        // shell serves them from its OS doc. Without this the configurable PDP price path
        // (ConfigurablePriceResolver LowestPriceOptionsProvider per-child BasePrice)
        // fires one catalogrule_product_price + one catalog_product_entity_tier_price query
        // PER child — the ~660-pair N+1 on a big configurable.
        $ruleMap = $this->getRulePriceMapByGroup($childIds);
        $tierMap = $this->getChildTierPrices($childIds);
        // Children inherit the parent's store scope; compute once (avoids getStoreIds() per child).
        $parentStoreId  = $product->getStoreId();
        $parentStoreIds = $product->getStoreIds();

        foreach ($childCollection as $child) {
            $cid = (int)$child->getId();
            $stock = $stockMap[$cid] ?? ['qty' => 0.0, 'is_in_stock' => false];
            $childProductsArray[] = [
                'entity_id' => $cid,
                'fm_extension_attributes' => $this->extensionProjection()->project($child),
                // The existing extension projection already read these at index time.
                // Retain them for inline swatch galleries; do not load them on the storefront.
                'media_gallery' => $child->getData('media_gallery') ?? ['images' => [], 'values' => []],
                'sku' => $child->getSku(),
                'name' => $child->getName(),
                'price' => (float)$child->getPrice(),
                // Rule-NEUTRAL base final price (special-if-active else regular). We must NOT
                // call $child->getFinalPrice() here: it dispatches catalog_product_get_final_price,
                // and when the indexer runs inside a frontend request (StockSyncer on an inventory
                // write during add-to-cart / order placement) the frontend catalog-rule observer
                // fires getRulePrice() once PER child — the ~660-query N+1 that kills checkout —
                // AND bakes the current shopper's group rule into a doc served to every group.
                // Per-group rules live in catalog_rule_prices; final_price stays group-neutral.
                'final_price' => $this->getBaseFinalPrice($child),
                'special_price' => (float)$child->getSpecialPrice(),
                'is_in_stock' => (bool)$stock['is_in_stock'],
                'stock_qty' => (float)$stock['qty'],
                'image' => $child->getImage() ?? '',
                'small_image' => $child->getSmallImage() ?? '',
                'thumbnail' => $child->getThumbnail() ?? '',
                // Serve catalog-rule/tier price from the doc. Always set the keys (even empty)
                // so the shell's price models short-circuit instead of falling through to SQL:
                // a missing key means "not indexed" native DB fallback (the N+1 we're killing).
                // catalog_rule_prices is the per-group map; catalog_rule_price is the group-0
                // scalar kept for backward compatibility.
                'catalog_rule_prices' => $ruleMap[$cid] ?? [],
                'catalog_rule_price' => isset($ruleMap[$cid][0]) ? ['rule_price' => (float)$ruleMap[$cid][0]] : [],
                'tier_prices' => $tierMap[$cid] ?? [],
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

    /**
     * Batch catalog-rule prices for a set of product ids, for ALL customer groups, in one
     * query on catalogrule_product_price (today's rule_date, website 1). Returns a nested
     * map so each product/child carries a {group_id => rule_price} slice and the read path
     * can resolve the current customer group's price. Replaces the previous per-child,
     * group-0-only lookup — one query instead of one getRulePrice() per child, and correct
     * for logged-in Wholesale/Retailer/General customers, not just guests.
     *
     * @param int[] $productIds
     * @return array<int, array<int, float>> product_id => [customer_group_id => rule_price]
     */
    private function getRulePriceMapByGroup(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }
        try {
            $connection = $this->productCollectionFactory->create()->getConnection();
            $select = $connection->select()
                ->from(
                    $connection->getTableName('catalogrule_product_price'),
                    ['product_id', 'customer_group_id', 'rule_price']
                )
                ->where('product_id IN (?)', $productIds)
                ->where('website_id = ?', 1)
                ->where('rule_date = ?', date('Y-m-d'));

            $map = [];
            foreach ($connection->fetchAll($select) as $row) {
                $map[(int)$row['product_id']][(int)$row['customer_group_id']] = (float)$row['rule_price'];
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Batch tier prices for a set of product ids — one query on catalog_product_entity_tier_price
     * instead of a per-child backend loadPriceData(). Emitted in the same shape the parent uses
     * ({customer_group_id, qty, value}) so the shell serves them from its doc. Scoped to website 0
     * (all-websites) + the default website (1); all_groups rows collapse to the ALL group id.
     *
     * @param int[] $productIds
     * @return array<int, array<int, array{customer_group_id:int,qty:float,value:float}>>
     */
    private function getChildTierPrices(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->productCollectionFactory->create()->getConnection();
        $select = $connection->select()
            ->from(
                ['tp' => $connection->getTableName('catalog_product_entity_tier_price')],
                ['all_groups', 'customer_group_id', 'qty', 'value']
            );
        // tp.row_id on Commerce: resolve to the entity id (join only on EE), keep the key name.
        $pid = $this->entityLink->productEntityId($select, 'tp');
        $select
            ->columns(['entity_id' => new \Zend_Db_Expr($pid)])
            ->where($pid . ' IN (?)', $productIds)
            ->where('tp.website_id IN (?)', [0, (int)$this->storeManager->getStore($this->getIndexStoreId())->getWebsiteId()]);

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int)$row['entity_id']][] = [
                'customer_group_id' => (int)$row['all_groups'] === 1
                    ? \Magento\Customer\Model\Group::CUST_GROUP_ALL
                    : (int)$row['customer_group_id'],
                'qty'   => (float)$row['qty'],
                'value' => (float)$row['value'],
            ];
        }
        return $map;
    }

    private function getCustomAttributesArray(\Magento\Catalog\Model\Product $product, array $configurableOptions): array
    {
        // A parent's child record only needs the fields the read path consumes:
        //  - the super-attribute option ids (raw) variant matching + swatch / jsonConfig,
        //  - type_id and status(label) child shell type + getAllowProducts' enabled check.
        // Every OTHER attribute a child carries is served from the child's OWN doc when a variant
        // is actually selected (getProductByAttributes reloads it by id), so indexing all ~50
        // attributes per child was pure bloat — and the dominant cost of projecting a big
        // configurable (its ~660+ children × every attribute). Iterating only the super-attributes
        // here (instead of $product->getAttributes()) is what makes configurable indexing fast.
        $customAttributes = [];
        foreach ($configurableOptions as $code) {
            $value = $product->getData($code);
            if ($value !== null && $value !== '') {
                $customAttributes[$code] = $value;   // raw option ids (color=86, size=89, …)
            }
        }
        $customAttributes['type_id'] = $product->getTypeId();
        // Status is indexed as its label; ShellProductBuilder maps "Disabled" back to numeric.
        $customAttributes['status'] =
            ((int) $product->getData('status') === \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED)
                ? 'Disabled'
                : 'Enabled';
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
