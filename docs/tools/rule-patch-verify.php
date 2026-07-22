<?php
/**
 * FastMagento catalog-rule-price patch PARITY harness.
 *
 * Proves CatalogRuleSyncer::patchRulePriceDocs leaves a doc byte-for-byte identical to a full
 * reprojection — for the rule-price fields it changes AND everything it must NOT touch.
 *
 * Per product id:
 *   1. Full reproject (executeList) -> mget -> BASELINE _source.
 *   2. Corrupt ONLY catalog_rule_prices/catalog_rule_price (top-level + children), index it.
 *   3. Run patchRulePriceDocs([id]) via reflection.
 *   4. mget -> assert PATCHED == BASELINE (and no reproject fallback).
 *
 * Usage: php app/code/ParkkTech/FastMagento/docs/tools/rule-patch-verify.php <id> [<id> ...]
 * No ids -> auto-picks one product that actually has a catalog-rule price + one configurable.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require __DIR__ . '/../../../../../bootstrap.php';

use ParkkTech\FastMagento\Model\OpenSearch\CatalogRuleSyncer;
use ParkkTech\FastMagento\Model\Indexer\ProductIndexer;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;

$b = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $b->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$indexer = $om->get(ProductIndexer::class);
$syncer = $om->get(CatalogRuleSyncer::class);
$osConfig = $om->get(OpenSearchConfig::class);
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$client = $om->get(\Magento\AdvancedSearch\Model\Client\ClientResolver::class)
    ->create($om->get(\Magento\Framework\Search\EngineResolverInterface::class)->getCurrentSearchEngine())
    ->getOpenSearchClient();
$index = $osConfig->getIndexName();

$mget = function (array $ids) use ($client, $index): array {
    $r = $client->mget(['index' => $index, 'body' => ['ids' => array_map('strval', $ids)]]);
    $out = [];
    foreach (($r['docs'] ?? []) as $d) {
        if (!empty($d['found'])) { $out[(int) $d['_id']] = $d['_source']; }
    }
    return $out;
};
$indexDoc = fn(int $id, array $src) => $client->index(['index' => $index, 'id' => (string) $id, 'body' => $src, 'refresh' => true]);
$rm = new ReflectionMethod(CatalogRuleSyncer::class, 'patchRulePriceDocs');
$rm->setAccessible(true);
$patch = fn(array $ids): array => $rm->invoke($syncer, $ids);

$summary = function (array $src): array {
    $s = ['catalog_rule_prices' => $src['catalog_rule_prices'] ?? null, 'catalog_rule_price' => $src['catalog_rule_price'] ?? null];
    foreach (($src['child_products'] ?? []) as $c) {
        $s['child[' . ($c['entity_id'] ?? '?') . ']'] = $c['catalog_rule_prices'] ?? null;
    }
    return $s;
};

$ids = array_map('intval', array_slice($argv, 1));
if (!$ids) {
    $conn = $resource->getConnection();
    $priced = (int) $conn->fetchOne(
        $conn->select()->from($conn->getTableName('catalogrule_product_price'), ['product_id'])
            ->where('rule_date = ?', date('Y-m-d'))->limit(1)
    );
    $conf = (int) $conn->fetchOne(
        $conn->select()->from($conn->getTableName('catalog_product_entity'), ['entity_id'])
            ->where('type_id = ?', 'configurable')->limit(1)
    );
    $ids = array_values(array_filter([$priced, $conf]));
    echo "Auto-picked ids: " . implode(', ', $ids) . "\n";
}

$pass = 0; $fail = 0;
foreach ($ids as $id) {
    echo str_repeat('=', 60) . "\nProduct $id\n";
    $indexer->executeList([$id]);
    $client->indices()->refresh(['index' => $index]);
    $baseline = $mget([$id])[$id] ?? null;
    if ($baseline === null) { echo "  SKIP: not indexed\n"; continue; }
    echo "  type=" . ($baseline['type_id'] ?? '?') . " baseline rule: " . json_encode($summary($baseline)) . "\n";

    $corrupt = $baseline;
    $corrupt['catalog_rule_prices'] = [999.99, 888.88];
    $corrupt['catalog_rule_price'] = ['rule_price' => 999.99];
    foreach (($corrupt['child_products'] ?? []) as $k => $c) {
        $corrupt['child_products'][$k]['catalog_rule_prices'] = [777.77];
        $corrupt['child_products'][$k]['catalog_rule_price'] = ['rule_price' => 777.77];
    }
    $indexDoc($id, $corrupt);

    $misses = $patch([$id]);
    $client->indices()->refresh(['index' => $index]);
    $patched = $mget([$id])[$id] ?? null;
    if ($misses) {
        $indexer->executeList($misses);
        $client->indices()->refresh(['index' => $index]);
        $patched = $mget([$id])[$id] ?? null;
        echo "  NOTE: patch fell back to reproject (miss)\n";
    }

    if ($patched == $baseline) {
        echo "  PASS: doc identical to full reproject.\n"; $pass++;
    } else {
        $fail++; echo "  FAIL: differs.\n    patched rule: " . json_encode($summary($patched ?? [])) . "\n";
        foreach (array_unique(array_merge(array_keys($baseline), array_keys($patched ?? []))) as $k) {
            if (($baseline[$k] ?? null) !== ($patched[$k] ?? null)) {
                echo "    DIFF[$k]: base=" . json_encode($baseline[$k] ?? null) . " patched=" . json_encode($patched[$k] ?? null) . "\n";
            }
        }
    }
    $indexer->executeList([$id]);
}
echo str_repeat('=', 60) . "\nRESULT: $pass passed, $fail failed.\n";
exit($fail ? 1 : 0);
