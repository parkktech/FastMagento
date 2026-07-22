<?php
/**
 * FastMagento collectTotals PROFILER (Stage 2 groundwork).
 *
 * Prints, for a quote, the wall-clock cost of collectTotals broken down per total collector
 * (subtotal / tax / shipping / discount / grand_total / weee / …) plus the collect_totals_before
 * observers, so Stage 2 targets the real hotspot instead of guessing. READ-ONLY (collectTotals
 * does not persist).
 *
 * Usage (from Magento root):
 *   php app/code/ParkkTech/FastMagento/docs/tools/collect-profile.php <quoteId> [area] [iterations]
 *     area       webapi_rest (default) | frontend | graphql
 *     iterations default 15 (warm median)
 *
 * Profiles in the CURRENT flag state — set the cart flags ON to profile the OS-served path.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require __DIR__ . '/../../../../../bootstrap.php';

$quoteId = (int) ($argv[1] ?? 0);
$area = (string) ($argv[2] ?? 'webapi_rest');
$iters = max(3, (int) ($argv[3] ?? 15));
if (!$quoteId) {
    fwrite(STDERR, "Usage: collect-profile.php <quoteId> [area] [iterations]\n");
    exit(1);
}

$b = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $b->getObjectManager();
$om->configure($om->get(\Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load($area));
$om->get(\Magento\Framework\App\State::class)->setAreaCode($area);

$scope = $om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
$flag = fn($p) => $scope->isSetFlag("fastmagento/cart/$p", 'store') ? 'ON' : 'off';

$quote = $om->create(\Magento\Quote\Model\Quote::class)->load($quoteId);
if (!$quote->getId()) {
    fwrite(STDERR, "quote $quoteId not found\n");
    exit(1);
}
$storeId = (int) $quote->getStoreId();

echo "quote $quoteId | area $area | group " . (int) $quote->getCustomerGroupId()
    . " | items " . count($quote->getAllItems()) . " | virtual " . ($quote->isVirtual() ? 'yes' : 'no') . "\n";
echo "flags: os_serve=" . $flag('os_serve_quote_items') . " optimistic=" . $flag('optimistic_stock')
    . " fast_stock=" . $flag('fast_stock_sync') . "\n";
echo str_repeat('-', 64) . "\n";

$median = function (array $xs) {
    sort($xs);
    $n = count($xs);
    return $n ? ($n % 2 ? $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2) : 0.0;
};
$ms = fn($t) => number_format($t * 1000, 1);

// ---- Whole collectTotals, warm median ----
$quote->collectTotals(); // warm
$whole = [];
for ($i = 0; $i < $iters; $i++) {
    $quote->setTotalsCollectedFlag(false);
    $t = microtime(true);
    $quote->collectTotals();
    $whole[] = microtime(true) - $t;
}
$grand = $quote->getGrandTotal();
$sub = $quote->getSubtotal();
$tax = $quote->getShippingAddress()->getTaxAmount() ?: $quote->getBillingAddress()->getTaxAmount();

// ---- Per-collector, replicating TotalsCollector::collectAddressTotals with timing ----
$collectorList = $om->get(\Magento\Quote\Model\Quote\TotalsCollectorList::class);
$shippingAssignmentFactory = $om->get(\Magento\Quote\Model\ShippingAssignmentFactory::class);
$shippingFactory = $om->get(\Magento\Quote\Model\ShippingFactory::class);
$totalFactory = $om->get(\Magento\Quote\Model\Quote\Address\TotalFactory::class);
$eventManager = $om->get(\Magento\Framework\Event\ManagerInterface::class);

$address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
$collectors = $collectorList->getCollectors($storeId);

$perCollector = []; // class => [times]
$beforeEv = [];
$afterEv = [];
for ($i = 0; $i < $iters; $i++) {
    $quote->setTotalsCollectedFlag(false);

    $shippingAssignment = $shippingAssignmentFactory->create();
    $shipping = $shippingFactory->create();
    $shipping->setMethod($address->getShippingMethod());
    $shipping->setAddress($address);
    $shippingAssignment->setShipping($shipping);
    $shippingAssignment->setItems($address->getAllItems());
    $total = $totalFactory->create(\Magento\Quote\Model\Quote\Address\Total::class);

    $t = microtime(true);
    $eventManager->dispatch('sales_quote_address_collect_totals_before',
        ['quote' => $quote, 'shipping_assignment' => $shippingAssignment, 'total' => $total]);
    $beforeEv[] = microtime(true) - $t;

    foreach ($collectors as $name => $collector) {
        $cls = get_class($collector);
        $t = microtime(true);
        $collector->collect($quote, $shippingAssignment, $total);
        $perCollector[$cls][] = microtime(true) - $t;
    }

    $t = microtime(true);
    $eventManager->dispatch('sales_quote_address_collect_totals_after',
        ['quote' => $quote, 'shipping_assignment' => $shippingAssignment, 'total' => $total]);
    $afterEv[] = microtime(true) - $t;
}

// ---- Report ----
echo "TOTALS: subtotal=$sub tax=$tax grand=$grand\n";
echo "collectTotals (whole)  median = " . $ms($median($whole)) . " ms  (min " . $ms(min($whole)) . ")\n";
echo str_repeat('-', 64) . "\n";
echo "per-collector (median ms, warm), descending:\n";

$rows = [];
$rows['[event] collect_totals_before'] = $median($beforeEv);
$rows['[event] collect_totals_after'] = $median($afterEv);
foreach ($perCollector as $cls => $times) {
    $short = preg_replace('#^Magento\\\\#', '', $cls);
    $rows[$short] = $median($times);
}
arsort($rows);
$sum = 0.0;
foreach ($rows as $label => $t) {
    $sum += $t;
    printf("  %8s ms   %s\n", $ms($t), $label);
}
echo str_repeat('-', 64) . "\n";
echo "sum of parts (median) = " . $ms($sum) . " ms\n";
