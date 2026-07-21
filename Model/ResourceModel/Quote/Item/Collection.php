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
            if (!$this->isOsServeEnabled() || !$this->isFrontendArea()) {
                return parent::_assignProducts();
            }

            $ids = array_values(array_unique(array_filter(array_map('intval', $this->_productIds))));
            if (!$ids) {
                return parent::_assignProducts();
            }

            // Custom options are not hydrated into the shell yet (getOptions/getOptionById).
            // A cart carrying them must stay 100% native so totals/price stay exact.
            foreach ($this as $item) {
                if ($item->getOptionByCode('option_ids')) {
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
        foreach ($docs as $doc) {
            $shell = $this->osShellBuilder->buildNoEavProductFromOsDoc($doc);
            $shell->setStoreId($storeId);
            // addItem() keys by getId() (entity_id) and runs no SQL; we never call load().
            $productCollection->addItem($shell);
        }

        $this->_eventManager->dispatch(
            'prepare_catalog_product_collection_prices',
            ['collection' => $productCollection, 'store_id' => $storeId]
        );
        $this->_eventManager->dispatch(
            'sales_quote_item_collection_products_after_load',
            ['collection' => $productCollection]
        );

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
        // Bundle/grouped cart hydration is not OS-proven yet → native.
        if (in_array($doc['type_id'], ['bundle', 'grouped'], true)) {
            return false;
        }
        return true;
    }

    private function isOsServeEnabled(): bool
    {
        return $this->osScopeConfig->isSetFlag(self::XML_PATH_OS_SERVE, ScopeInterface::SCOPE_STORE);
    }

    private function isFrontendArea(): bool
    {
        try {
            return $this->osAppState->getAreaCode() === Area::AREA_FRONTEND;
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
