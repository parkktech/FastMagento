<?php
/**
 * FastMagento cart/checkout verification helper for the supervised order test.
 * READ-ONLY (never places or saves an order; collectTotals does not persist).
 *
 * Usage (run from Magento root):
 *   php app/code/ParkkTech/FastMagento/docs/tools/cart-verify.php totals <quoteId> [area]
 *       Print collected totals + per-line price/tax/salable for a quote, in the CURRENT flag
 *       state and area (default webapi_rest). Run once with the flags OFF, once ON, diff.
 *
 *   php app/code/ParkkTech/FastMagento/docs/tools/cart-verify.php stock <sku> [<sku> ...]
 *       Print authoritative MSI salable qty per sku. Snapshot BEFORE and AFTER placing a real
 *       order to confirm the decrement (and that it never goes negative).
 *
 *   php app/code/ParkkTech/FastMagento/docs/tools/cart-verify.php flags
 *       Show the current flag state.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require __DIR__ . '/../../../../../bootstrap.php';

$cmd = $argv[1] ?? '';
$b = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $b->getObjectManager();

$flag = fn($p) => $om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class)
    ->isSetFlag("fastmagento/cart/$p", 'store') ? 'ON' : 'off';

switch ($cmd) {
    case 'flags':
        echo "os_serve_quote_items = " . $flag('os_serve_quote_items') . "\n";
        echo "optimistic_stock     = " . $flag('optimistic_stock') . "\n";
        break;

    case 'totals':
        $quoteId = (int)($argv[2] ?? 0);
        $area = (string)($argv[3] ?? 'webapi_rest');
        $om->configure($om->get(\Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load($area));
        $om->get(\Magento\Framework\App\State::class)->setAreaCode($area);
        $quote = $om->create(\Magento\Quote\Model\Quote::class)->load($quoteId);
        echo "quote $quoteId | group " . (int)$quote->getCustomerGroupId()
            . " | area $area | os_serve=" . $flag('os_serve_quote_items')
            . " optimistic=" . $flag('optimistic_stock') . "\n";
        echo str_repeat('-', 74) . "\n";
        foreach ($quote->getItemsCollection()->getItems() as $it) {
            $p = $it->getProduct();
            printf("  pid=%-6s %-12s final=%-10s tax_class=%s salable=%s\n",
                $p->getId(), $p->getTypeId(),
                number_format((float)$p->getFinalPrice($it->getQty()), 4),
                (string)$p->getTaxClassId(), $p->isSalable() ? 'Y' : 'N');
        }
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        echo str_repeat('-', 74) . "\n";
        printf("TOTALS subtotal=%s subWithDiscount=%s tax=%s grand=%s\n",
            number_format((float)$quote->getSubtotal(), 4),
            number_format((float)$quote->getSubtotalWithDiscount(), 4),
            number_format((float)$quote->getShippingAddress()->getTaxAmount()
                + (float)$quote->getBillingAddress()->getTaxAmount(), 4),
            number_format((float)$quote->getGrandTotal(), 4));
        break;

    case 'stock':
        $skus = array_slice($argv, 2);
        if (!$skus) { echo "usage: cart-verify.php stock <sku> [<sku> ...]\n"; break; }
        $getSalable = $om->get(\Magento\InventorySalesApi\Api\GetProductSalableQtyInterface::class);
        $stockResolver = $om->get(\Magento\InventorySalesApi\Api\StockResolverInterface::class);
        $storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
        $website = $storeManager->getWebsite();
        $stockId = (int)$stockResolver->execute(
            \Magento\InventorySalesApi\Api\Data\SalesChannelInterface::TYPE_WEBSITE,
            $website->getCode()
        )->getStockId();
        echo "authoritative MSI salable qty (stockId $stockId, website {$website->getCode()}):\n";
        foreach ($skus as $sku) {
            try {
                printf("  %-32s %s\n", $sku, $getSalable->execute($sku, $stockId));
            } catch (\Throwable $e) {
                printf("  %-32s (%s)\n", $sku, $e->getMessage());
            }
        }
        break;

    default:
        echo "commands: flags | totals <quoteId> [area] | stock <sku> [<sku> ...]\n";
}
