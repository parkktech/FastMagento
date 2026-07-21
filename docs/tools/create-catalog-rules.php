<?php
/**
 * Create a representative set of catalog price rules to exercise the FastMagento
 * OpenSearch serving layer across customer groups / discount types.
 *
 * Usage:  php create-catalog-rules.php [reset]
 *   reset  -> delete all existing catalog rules first
 *
 * After running, reindex:  bin/magento indexer:reindex catalogrule_rule
 * then reproject the affected products into OpenSearch.
 */
require __DIR__ . '/../../../../../../app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

/** @var \Magento\CatalogRule\Model\RuleFactory $ruleFactory */
$ruleFactory = $om->get(\Magento\CatalogRule\Model\RuleFactory::class);
/** @var \Magento\CatalogRule\Api\CatalogRuleRepositoryInterface $ruleRepo */
$ruleRepo = $om->get(\Magento\CatalogRule\Api\CatalogRuleRepositoryInterface::class);
/** @var \Magento\Framework\App\ResourceConnection $res */
$res = $om->get(\Magento\Framework\App\ResourceConnection::class);
$conn = $res->getConnection();

$reset = in_array('reset', $argv, true);
if ($reset) {
    $ids = $conn->fetchCol($conn->select()->from($conn->getTableName('catalogrule'), ['rule_id']));
    foreach ($ids as $id) {
        try { $ruleRepo->deleteById((int)$id); } catch (\Throwable $e) { echo "  del $id failed: {$e->getMessage()}\n"; }
    }
    echo "Deleted " . count($ids) . " existing rules.\n";
}

$websiteId = 1;

// group ids: 0=NOT LOGGED IN, 1=General, 2=Wholesale, 3=Retailer
$rules = [
    [
        'name' => 'FM Test: 10% off ALL groups',
        'groups' => [0, 1, 2, 3],
        'action' => 'by_percent', 'amount' => 10, 'sort' => 10, 'stop' => 0,
    ],
    [
        'name' => 'FM Test: 15% off WHOLESALE only',
        'groups' => [2],
        'action' => 'by_percent', 'amount' => 15, 'sort' => 20, 'stop' => 0,
    ],
    [
        'name' => 'FM Test: $5 fixed off GENERAL only',
        'groups' => [1],
        'action' => 'by_fixed', 'amount' => 5, 'sort' => 20, 'stop' => 0,
    ],
];

foreach ($rules as $r) {
    $rule = $ruleFactory->create();
    $rule->setName($r['name'])
        ->setDescription('FastMagento catalog-rule test fixture')
        ->setIsActive(1)
        ->setCustomerGroupIds($r['groups'])
        ->setWebsiteIds([$websiteId])
        ->setFromDate(null)
        ->setToDate(null)
        ->setSimpleAction($r['action'])
        ->setDiscountAmount($r['amount'])
        ->setStopRulesProcessing($r['stop'])
        ->setSortOrder($r['sort']);
    // No conditions => applies to ALL products.
    $rule->getConditions()->setConditions([]);
    $rule->save();
    echo "Created rule #{$rule->getId()}: {$r['name']} (groups " . implode(',', $r['groups']) . ")\n";
}

echo "Done. Now run: bin/magento indexer:reindex catalogrule_rule\n";
