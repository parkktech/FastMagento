<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\ResourceModel\Quote\Item;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Store\Model\ScopeInterface;
use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;
use ParkkTech\FastMagento\Helper\ShellProductBuilder;

/**
 * OpenSearch-served quote-item product collection.
 *
 * Preference on the core quote-item resource collection that overrides ONLY
 * {@see _assignProducts()} — the ~217-SQL native `Product\Collection` build that hydrates
 * every cart/checkout line's product from MySQL (product/EAV ≈119, MSI stock ≈71,
 * downloadable ≈27). When the flag is on and every line's product is fully servable from
 * OpenSearch, the products are hydrated from indexed shells instead — no product/EAV/
 * downloadable SQL. Live stock is still loaded natively via AddStockItemsObserver (see
 * assignProductsFromOs), so a stale index can never oversell.
 *
 * SAFETY: this is checkout. The design is "minimal divergence + hard native fallback".
 *  - Flag off, non-frontend area, custom-option carts, bundle/grouped, downloadable without
 *    indexed links, or ANY id missing/partial in the index → the WHOLE collection falls back
 *    to `parent::_assignProducts()` (100% native, byte-for-byte identical to stock Magento).
 *  - Any Throwable in the OS branch → same native fallback.
 *
 * The core method uses PRIVATE members ($recollectQuote, $config, getOptionProductIds(),
 * isValidProduct()) a subclass can't reach, so the OS branch injects its own
 * `Quote\Model\Config`, keeps a local recollect flag, and copies those two small helpers
 * inline. The fallback branch uses the parent's own privates and is unaffected.
 */
class Collection extends \Magento\Quote\Model\ResourceModel\Quote\Item\Collection
{
    private const XML_PATH_OS_SERVE = 'fastmagento/cart/os_serve_quote_items';
    private const XML_PATH_OPTIMISTIC_STOCK = 'fastmagento/cart/optimistic_stock';

    /** @var OpenSearchPdpFetcher */
    private $osFetcher;

    /** @var ShellProductBuilder */
    private $osShellBuilder;

    /** @var AppState */
    private $osAppState;

    /** @var ScopeConfigInterface */
    private $osScopeConfig;

    /**
     * Own handle to Quote\Model\Config — the parent's `config` is private, and the OS branch
     * needs isEnabled() to decide whether to run checkData() (mirrors core exactly).
     *
     * @var \Magento\Quote\Model\Config
     */
    private $osQuoteModelConfig;

    /** @var bool local mirror of the parent's private $recollectQuote */
    private $osRecollectQuote = false;

    /**
     * FastMagento deps are appended AFTER all of core's params, nullable with an
     * ObjectManager fallback, so the parent's parameter positions are preserved (a preference
     * subclass must forward the full core signature). Same pattern as ShellNoEavProduct.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\Data\Collection\EntityFactory $entityFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Data\Collection\Db\FetchStrategyInterface $fetchStrategy,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\Model\ResourceModel\Db\VersionControl\Snapshot $entitySnapshot,
        \Magento\Quote\Model\ResourceModel\Quote\Item\Option\CollectionFactory $itemOptionCollectionFactory,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magento\Quote\Model\Quote\Config $quoteConfig,
        ?\Magento\Framework\DB\Adapter\AdapterInterface $connection = null,
        ?\Magento\Framework\Model\ResourceModel\Db\AbstractDb $resource = null,
        ?\Magento\Store\Model\StoreManagerInterface $storeManager = null,
        ?\Magento\Quote\Model\Config $config = null,
        ?OpenSearchPdpFetcher $osFetcher = null,
        ?ShellProductBuilder $osShellBuilder = null,
        ?AppState $osAppState = null,
        ?ScopeConfigInterface $osScopeConfig = null,
        ?\Magento\Quote\Model\Config $osQuoteModelConfig = null
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $entitySnapshot,
            $itemOptionCollectionFactory,
            $productCollectionFactory,
            $quoteConfig,
            $connection,
            $resource,
            $storeManager,
            $config
        );
        $om = ObjectManager::getInstance();
        $this->osFetcher = $osFetcher ?? $om->get(OpenSearchPdpFetcher::class);
        $this->osShellBuilder = $osShellBuilder ?? $om->get(ShellProductBuilder::class);
        $this->osAppState = $osAppState ?? $om->get(AppState::class);
        $this->osScopeConfig = $osScopeConfig ?? $om->get(ScopeConfigInterface::class);
        $this->osQuoteModelConfig = $osQuoteModelConfig ?? $om->get(\Magento\Quote\Model\Config::class);
    }

    /**
     * Add products to items and item options — OS-served when safe, native otherwise.
     *
     * @return $this
     */
    protected function _assignProducts(): self
    {
        try {
            if (!$this->isOsServeEnabled() || !$this->isServableArea()) {
                return parent::_assignProducts();
            }

            $ids = array_values(array_unique(array_filter(array_map('intval', $this->_productIds))));
            if (!$ids) {
                return parent::_assignProducts();
            }

            // Pre-fetch bail-outs, straight off the quote_item rows (no OS round-trip):
            //  - Composite lines (configurable/bundle/grouped): MEASURED net latency LOSS —
            //    the parent needs its ~660 children built/scanned, which costs far more than
            //    the SQL it saves (~2x). Read the raw product_type off the row via
            //    getData('product_type') — NOT getProductType(), which lazy-loads getProduct()
            //    and itself builds the configurable shell (measured +90ms). isDocServable() is
            //    the belt-and-suspenders guarantee if the row column is ever blank.
            //  - Custom options (option_ids): not hydrated into the shell yet (getOptions/
            //    getOptionById), so totals could drift — stay 100% native.
            $canServeConfigurable = $this->isOptimisticStockEnabled();
            foreach ($this as $item) {
                $type = (string) $item->getData('product_type');
                // Bundle/grouped: multi-child-per-line selection not OS-proven → native.
                // Configurable: only OS-serve it when OPTIMISTIC mode is on — that is what
                //   suspends the qty-set stock observers whose MSI child cascade otherwise
                //   makes a served configurable FAR slower (measured 255 vs 79 queries).
                //   Without optimistic, configurables stay native (safe parity).
                // Custom options (option_ids): not hydrated into the shell yet → native.
                if (in_array($type, ['bundle', 'grouped'], true)
                    || ($type === 'configurable' && !$canServeConfigurable)
                    || $item->getOptionByCode('option_ids')
                ) {
                    return parent::_assignProducts();
                }
            }

            // ONE mget for the whole cart.
            $docs = $this->osFetcher->fetchByIds($ids);

            // Hard fallback: never half-serve a mixed cart. Any id missing OR not fully
            // servable → the WHOLE collection goes native (safe, byte-for-byte identical).
            foreach ($ids as $id) {
                if (!isset($docs[$id]) || !$this->isDocServable($docs[$id])) {
                    return parent::_assignProducts();
                }
            }

            return $this->assignProductsFromOs($docs);
        } catch (\Throwable $e) {
            $this->_logger->error('FastMagento OS quote-item serve failed; native fallback: ' . $e->getMessage());
            return parent::_assignProducts();
        }
    }

    /**
     * Build a real Product\Collection from OS-hydrated shells (no load(), no product SQL),
     * then run core `_assignProducts`'s exact body: dispatch both collection events so
     * AddStockItemsObserver (live stock), catalog-rule and 3rd-party observers still fire,
     * then the identical per-item setProduct/checkData loop.
     *
     * @param array<int, array<string, mixed>> $docs entity_id => _source
     * @return $this
     */
    private function assignProductsFromOs(array $docs): self
    {
        $storeId = $this->getStoreId();

        /** @var ProductCollection $productCollection */
        $productCollection = $this->_productCollectionFactory->create();
        // Mark the collection loaded BEFORE populating it. getItemById()/getItems() call
        // load() on an unloaded collection — which here fires a full-catalog DB read that
        // both defeats the point AND collides with our pre-added shells ("same ID already
        // exists"). Flagging it loaded makes those accessors serve our in-memory shells with
        // zero SQL. _setIsLoaded is protected on Magento\Framework\Data\Collection; a bound
        // closure reaches it without reflection.
        (function () {
            $this->_setIsLoaded(true);
        })->call($productCollection);
        foreach ($docs as $doc) {
            // Cart-mode build (hydrateChildren=false): a configurable parent is built WITHOUT its
            // ~660 sibling variants — the purchased variant is its own quote item, wired below.
            $shell = $this->osShellBuilder->buildNoEavProductFromOsDoc($doc, false);
            $shell->setStoreId($storeId);
            // addItem() keys by getId() (entity_id) and runs no SQL; we never call load().
            $productCollection->addItem($shell);
        }

        // Wire each configurable PARENT to ONLY the child variant(s) actually in the cart (child
        // quote items carry parent_item_id). The purchased child is already a shell in the
        // collection; registering it under child_products_<parentId> makes the Configurable
        // override's getUsedProducts()/getProductByAttributes() resolve instantly to the in-cart
        // variant — no native used-product DB load, no child fan-out. This is what lets a
        // configurable line cost ~the same as a plain simple line (only reached in optimistic
        // mode, where the qty-set stock cascade is already suspended).
        foreach ($this as $item) {
            try {
                $parentItem = $item->getParentItem();
            } catch (NoSuchEntityException $e) {
                $parentItem = null;
            }
            if (!$parentItem) {
                continue;
            }
            $parentPid = (int) $parentItem->getProductId();
            $childShell = $productCollection->getItemById((int) $item->getProductId());
            if ($parentPid && $childShell) {
                $this->osShellBuilder->registerCartChildren($parentPid, [$childShell]);
            }
        }

        $this->_eventManager->dispatch(
            'prepare_catalog_product_collection_prices',
            ['collection' => $productCollection, 'store_id' => $storeId]
        );
        // sales_quote_item_collection_products_after_load drives the MSI stock preload
        // (AddStockItemsObserver + InventoryCatalog\PreloadCache) — ~half the remaining
        // per-load queries. It OVERWRITES each shell's already-live indexed stock_item with a
        // fresh DB read. In "optimistic stock" mode we skip it: the shells keep their
        // StockSyncer-live OS stock (correct for the cart's in-stock/low-stock UX), and the
        // ONLY authoritative gate — MSI's CheckItemsQuantity at order placement, which
        // re-derives salable qty by SKU and throws if short — is untouched, so a stale index
        // can still never oversell (worst case: a rare graceful rejection at placement).
        // qty-change validation (QuantityValidator, on sales_quote_item_qty_set_after) is a
        // different event and still fires. Own flag, default off.
        if (!$this->isOptimisticStockEnabled()) {
            $this->_eventManager->dispatch(
                'sales_quote_item_collection_products_after_load',
                ['collection' => $productCollection]
            );
        }

        foreach ($this as $item) {
            /** @var ProductInterface $product */
            $product = $productCollection->getItemById($item->getProductId());
            try {
                /** @var QuoteItem $item */
                $parentItem = $item->getParentItem();
                $parentProduct = $parentItem ? $parentItem->getProduct() : null;
            } catch (NoSuchEntityException $exception) {
                $parentItem = null;
                $parentProduct = null;
                $this->_logger->error($exception);
            }
            $qtyOptions = [];
            if ($this->osIsValidProduct($product) && (!$parentItem || $this->osIsValidProduct($parentProduct))) {
                $product->setCustomOptions([]);
                $optionProductIds = $this->osGetOptionProductIds($item, $product, $productCollection);
                foreach ($optionProductIds as $optionProductId) {
                    $qtyOption = $item->getOptionByCode('product_qty_' . $optionProductId);
                    if ($qtyOption) {
                        $qtyOptions[$optionProductId] = $qtyOption;
                    }
                }
            } else {
                $item->isDeleted(true);
                $this->osRecollectQuote = true;
            }
            if (!$item->isDeleted()) {
                $item->setQtyOptions($qtyOptions)->setProduct($product);
                if ($this->osQuoteModelConfig->isEnabled()) {
                    $item->checkData();
                }
            }
        }
        if ($this->osRecollectQuote && $this->_quote) {
            $this->_quote->setTotalsCollectedFlag(false);
        }

        return $this;
    }

    /**
     * Is a doc complete enough to hydrate a checkout-safe shell? Conservative on purpose:
     * anything uncertain returns false and the whole cart goes native.
     *
     * @param array<string, mixed> $doc
     */
    private function isDocServable(array $doc): bool
    {
        if (empty($doc['sku']) || empty($doc['type_id'])) {
            return false;
        }
        // status must be present AND ENABLED. A blank/disabled status makes core silently
        // delete the item (cart empties); let native handle that exact bookkeeping.
        if (!isset($doc['status']) || (int) $doc['status'] !== ProductStatus::STATUS_ENABLED) {
            return false;
        }
        // Stock item is read unconditionally by setProduct()/AddStockItemsObserver; a shell
        // without one can null-fatal the quote load.
        if (empty($doc['extension_attributes']['stock_item'])) {
            return false;
        }
        // Downloadable without indexed links is not cart-proven yet (gap b) → native.
        if ($doc['type_id'] === 'downloadable' && empty($doc['downloadable_links'])) {
            return false;
        }
        // Bundle/grouped → native (multi-child-per-line selection not OS-proven yet).
        if (in_array($doc['type_id'], ['bundle', 'grouped'], true)) {
            return false;
        }
        // Configurable → served ONLY in optimistic mode, which suspends the qty-set stock
        // observers whose MSI child cascade would otherwise dominate (255 vs 79 queries). Built
        // child-less + wired to the in-cart variant in assignProductsFromOs. Else native.
        if ($doc['type_id'] === 'configurable' && !$this->isOptimisticStockEnabled()) {
            return false;
        }
        return true;
    }

    private function isOsServeEnabled(): bool
    {
        return $this->osScopeConfig->isSetFlag(self::XML_PATH_OS_SERVE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Optimistic-stock mode: trust the live-indexed OS stock for pre-placement UX and let MSI's
     * placement-time CheckItemsQuantity be the sole authoritative gate. Skips the redundant
     * per-load MSI stock preload. Separate flag, default off (checkout stock behaviour change).
     */
    private function isOptimisticStockEnabled(): bool
    {
        return $this->osScopeConfig->isSetFlag(self::XML_PATH_OPTIMISTIC_STOCK, ScopeInterface::SCOPE_STORE);
    }

    /**
     * OS-serve the customer-facing quote loads: the storefront cart (frontend) AND the
     * checkout REST/GraphQL calls (webapi_rest/graphql), which re-load the quote-item
     * collection several times per checkout and are the real latency path. Admin order-create
     * (adminhtml) and cron stay native — they need the full native product (disabled/OOS
     * products, admin-only pricing) and are not latency-sensitive.
     */
    private function isServableArea(): bool
    {
        try {
            return in_array(
                $this->osAppState->getAreaCode(),
                [Area::AREA_FRONTEND, Area::AREA_WEBAPI_REST, Area::AREA_GRAPHQL],
                true
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Copied inline from core Collection (private there): resolve option products for an item.
     *
     * @param QuoteItem $item
     * @param ProductInterface $product
     * @param ProductCollection $productCollection
     * @return array
     */
    private function osGetOptionProductIds(
        QuoteItem $item,
        ProductInterface $product,
        ProductCollection $productCollection
    ): array {
        $optionProductIds = [];
        foreach ($item->getOptions() as $option) {
            $product->getTypeInstance()->assignProductToOption(
                $productCollection->getItemById($option->getProductId()),
                $option,
                $product
            );

            if (is_object($option->getProduct()) && $option->getProduct()->getId() != $product->getId()) {
                $isValidProduct = $this->osIsValidProduct($option->getProduct());
                if (!$isValidProduct && !$item->isDeleted()) {
                    $item->isDeleted(true);
                    $this->osRecollectQuote = true;
                    continue;
                }
                $optionProductIds[$option->getProduct()->getId()] = $option->getProduct()->getId();
            }
        }

        return $optionProductIds;
    }

    /**
     * Copied inline from core Collection (private there): a product is valid if present and
     * not disabled.
     *
     * @param ProductInterface|null $product
     */
    private function osIsValidProduct(?ProductInterface $product): bool
    {
        return $product && (int) $product->getStatus() !== ProductStatus::STATUS_DISABLED;
    }
}
