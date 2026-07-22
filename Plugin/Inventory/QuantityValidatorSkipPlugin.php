<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Inventory;

use Magento\CatalogInventory\Observer\QuantityValidatorObserver;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Store\Model\ScopeInterface;

/**
 * Optimistic-stock: skip the per-qty-set MSI stock validation on the customer-facing quote path.
 *
 * On this multi-source store, {@see QuantityValidatorObserver} (bound to
 * sales_quote_item_qty_set_after) is the single biggest query source on a physical-product
 * cart — it re-resolves salable qty per line across every inventory source (inventory_source_
 * item / inventory_source_stock_link / cataloginventory_stock_status), at BOTH collection load
 * and every collectTotals, ~18 queries/product. It is pre-placement UX only: the authoritative
 * gate is MSI's CheckItemsQuantity at order placement (AppendReservations::reserve), which
 * re-checks salable qty by SKU and throws if short — so skipping this cannot oversell.
 *
 * Gated by BOTH flags (os_serve_quote_items + optimistic_stock) and the servable areas
 * (frontend / webapi_rest / graphql). adminhtml order-create and cron keep full native
 * validation. TRADE-OFF (the opt-in optimistic contract): a low-/out-of-stock line is no longer
 * capped or flagged in the cart — it rides to the placement step and is rejected there.
 */
class QuantityValidatorSkipPlugin
{
    private const XML_PATH_OS_SERVE = 'fastmagento/cart/os_serve_quote_items';
    private const XML_PATH_OPTIMISTIC_STOCK = 'fastmagento/cart/optimistic_stock';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AppState $appState
    ) {
    }

    /**
     * @param QuantityValidatorObserver $subject
     * @param callable $proceed
     * @param EventObserver $observer
     * @return void
     */
    public function aroundExecute(QuantityValidatorObserver $subject, callable $proceed, EventObserver $observer)
    {
        if ($this->shouldSkip()) {
            return; // trust the placement-time CheckItemsQuantity gate
        }
        return $proceed($observer);
    }

    private function shouldSkip(): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_OS_SERVE, ScopeInterface::SCOPE_STORE)
            || !$this->scopeConfig->isSetFlag(self::XML_PATH_OPTIMISTIC_STOCK, ScopeInterface::SCOPE_STORE)
        ) {
            return false;
        }
        try {
            return in_array(
                $this->appState->getAreaCode(),
                [Area::AREA_FRONTEND, Area::AREA_WEBAPI_REST, Area::AREA_GRAPHQL],
                true
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
