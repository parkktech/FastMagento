<?php
/**
 * Stress fixture: create N catalog price rules to exercise checkout / reprojection at scale.
 * Usage: php stress-catalog-rules.php <count> [reset]
 * Rules are small, mostly always-active, across mixed customer groups, no conditions
 * (apply to all products) so they stack on the complex configurable's children too.
 */
require __DIR__ . '/../../../../../../app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$ruleFactory = $om->get(\Magento\CatalogRule\Model\RuleFactory::class);
$ruleRepo = $om->get(\Magento\CatalogRule\Api\CatalogRuleRepositoryInterface::class);
$conn = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();

$count = (int)($argv[1] ?? 1000);
$reset = in_array('reset', $argv, true);
if ($reset) {
    $ids = $conn->fetchCol($conn->select()->from($conn->getTableName('catalogrule'), ['rule_id']));
    foreach ($ids as $id) { try { $ruleRepo->deleteById((int)$id); } catch (\Throwable $e) {} }
    echo "Deleted " . count($ids) . " existing rules.\n";
}

$groups = [[0,1,2,3],[2],[1],[3],[0,2],[1,2,3]];
$actions = ['by_percent','by_fixed'];
$t0 = microtime(true);
for ($i = 1; $i <= $count; $i++) {
    $rule = $ruleFactory->create();
    $rule->setName('FM Stress Rule ' . $i)
        ->setIsActive(1)
        ->setCustomerGroupIds($groups[$i % count($groups)])
        ->setWebsiteIds([1])
        ->setFromDate(null)->setToDate(null)
        ->setSimpleAction($actions[$i % 2])
        ->setDiscountAmount(($i % 5) + 1)      // 1..5 (% or $)
        ->setStopRulesProcessing(0)
        ->setSortOrder(100 + $i);
    $rule->getConditions()->setConditions([]);
    $rule->save();
    if ($i % 100 === 0) { echo "  created $i/$count (" . round(microtime(true)-$t0,1) . "s)\n"; }
}
echo "Created $count rules in " . round(microtime(true)-$t0,1) . "s. Now reindex catalogrule_rule.\n";
