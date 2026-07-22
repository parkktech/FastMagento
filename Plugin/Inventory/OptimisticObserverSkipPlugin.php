<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Inventory;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Optimistic-stock: skip a per-qty-set stock-validation observer on the customer-facing quote
 * path. Generic (binds to any ObserverInterface) so the same gate covers core's
 * QuantityValidatorObserver AND 3rd-party marketplace observers that re-load/re-save stock on
 * every sales_quote_item_qty_set_after — the cascade that makes a physical/configurable cart
 * fire hundreds of MSI source-item queries at load and on every collectTotals.
 *
 * SAFETY: order placement's MSI CheckItemsQuantity remains the sole authoritative gate, so
 * skipping pre-placement validation cannot oversell. Gated by BOTH flags (os_serve_quote_items
 * + optimistic_stock) and the servable areas (frontend/webapi_rest/graphql); adminhtml + cron
 * keep full validation. TRADE-OFF: also suspends any marketplace per-line rule that observer
 * enforces (e.g. max-sale-qty) until placement — opt-in, off by default, owner's call.
 */
class OptimisticObserverSkipPlugin
{
    private const XML_PATH_OS_SERVE = 'fastmagento/cart/os_serve_quote_items';
    private const XML_PATH_OPTIMISTIC_STOCK = 'fastmagento/cart/optimistic_stock';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AppState $appState
    ) {
    }

    /**
     * @param ObserverInterface $subject
     * @param callable $proceed
     * @param EventObserver $observer
     * @return void
     */
    public function aroundExecute(ObserverInterface $subject, callable $proceed, EventObserver $observer)
    {
        if ($this->shouldSkip()) {
            return;
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
