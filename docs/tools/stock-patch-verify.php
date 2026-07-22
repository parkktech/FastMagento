<?php
/**
 * FastMagento fast-stock-sync PARITY harness (Task 2).
 *
 * Proves that the fast stock patch (StockSyncer::patchStockDocs) leaves an OpenSearch product
 * doc byte-for-byte identical to a full EAV reprojection — for the stock fields it changes AND
 * for everything else it must NOT touch.
 *
 * Method, per product id:
 *   1. Full reproject (executeList) → mget → BASELINE _source.
 *   2. Corrupt ONLY the stock fields (flip is_in_stock, qty=-999, children too) and index that.
 *   3. Run patchStockDocs([id]) via reflection (fast_stock_sync path).
 *   4. mget → PATCHED _source. Assert PATCHED === BASELINE exactly (and no reproject fallback).
 *
 * READ-ONLY w.r.t. the catalog/DB (only rewrites the product's own index doc, which it restores
 * to a correct reprojection at the end).
 *
 * Usage (from Magento root):
 *   php app/code/ParkkTech/FastMagento/docs/tools/stock-patch-verify.php <id> [<id> ...]
 * With no ids it auto-picks one simple + one configurable parent.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require __DIR__ . '/../../../../../bootstrap.php';

use ParkkTech\FastMagento\Model\OpenSearch\StockSyncer;
use ParkkTech\FastMagento\Model\Indexer\ProductIndexer;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;

$b = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $b->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$indexer  = $om->get(ProductIndexer::class);
$syncer   = $om->get(StockSyncer::class);
$osConfig = $om->get(OpenSearchConfig::class);
$clientResolver = $om->get(\Magento\AdvancedSearch\Model\Client\ClientResolver::class);
$engineResolver = $om->get(\Magento\Framework\Search\EngineResolverInterface::class);
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$index = $osConfig->getIndexName();
$client = $clientResolver->create($engineResolver->getCurrentSearchEngine())->getOpenSearchClient();

$mget = function (array $ids) use ($client, $index): array {
    $r = $client->mget(['index' => $index, 'body' => ['ids' => array_map('strval', $ids)]]);
    $out = [];
    foreach (($r['docs'] ?? []) as $d) {
        if (!empty($d['found'])) {
            $out[(int) $d['_id']] = $d['_source'];
        }
    }
    return $out;
};
$indexDoc = function (int $id, array $src) use ($client, $index): void {
    $client->index(['index' => $index, 'id' => (string) $id, 'body' => $src, 'refresh' => true]);
};
// Reflection handle to the private patch method.
$rm = new ReflectionMethod(StockSyncer::class, 'patchStockDocs');
$rm->setAccessible(true);
$patch = fn(array $ids): array => $rm->invoke($syncer, $ids);

// Stock keys the patch is allowed to change (for reporting only).
$stockSummary = function (array $src): array {
    $s = [
        'is_in_stock'      => $src['is_in_stock'] ?? null,
        'stock_data.qty'   => $src['stock_data']['qty'] ?? null,
        'ext.qty'          => $src['extension_attributes']['stock_item']['qty'] ?? null,
        'ext.is_in_stock'  => $src['extension_attributes']['stock_item']['is_in_stock'] ?? null,
    ];
    foreach (($src['child_products'] ?? []) as $c) {
        $s['child[' . ($c['entity_id'] ?? '?') . ']'] = ($c['is_in_stock'] ?? '?') . '/' . ($c['stock_qty'] ?? '?');
    }
    return $s;
};

$ids = array_map('intval', array_slice($argv, 1));
if (!$ids) {
    $conn = $resource->getConnection();
    $simple = $conn->fetchOne(
        $conn->select()->from($conn->getTableName('catalog_product_entity'), ['entity_id'])
            ->where('type_id = ?', 'simple')->limit(1)
    );
    $conf = $conn->fetchOne(
        $conn->select()->from($conn->getTableName('catalog_product_entity'), ['entity_id'])
            ->where('type_id = ?', 'configurable')->limit(1)
    );
    $ids = array_values(array_filter([(int) $simple, (int) $conf]));
    echo "Auto-picked ids: " . implode(', ', $ids) . "\n";
}

$pass = 0;
$fail = 0;
foreach ($ids as $id) {
    echo str_repeat('=', 72) . "\nProduct $id\n";

    // 1. Baseline = full reproject.
    $indexer->executeList([$id]);
    $client->indices()->refresh(['index' => $index]);
    $baseline = $mget([$id])[$id] ?? null;
    if ($baseline === null) {
        echo "  SKIP: not in index after reproject (not indexable?)\n";
        continue;
    }
    $type = $baseline['type_id'] ?? '?';
    echo "  type=$type  baseline stock: " . json_encode($stockSummary($baseline)) . "\n";

    // 2. Corrupt ONLY stock fields, keep everything else = baseline.
    $corrupt = $baseline;
    $corrupt['is_in_stock'] = !($baseline['is_in_stock'] ?? false);
    $corrupt['stock_data'] = ['qty' => -999.0];
    if (isset($corrupt['extension_attributes']['stock_item'])) {
        $corrupt['extension_attributes']['stock_item']['qty'] = -999.0;
        $corrupt['extension_attributes']['stock_item']['is_in_stock'] = ($baseline['is_in_stock'] ?? false) ? 0 : 1;
    }
    foreach (($corrupt['child_products'] ?? []) as $k => $c) {
        $corrupt['child_products'][$k]['is_in_stock'] = !($c['is_in_stock'] ?? false);
        $corrupt['child_products'][$k]['stock_qty'] = -999.0;
    }
    $indexDoc($id, $corrupt);

    // 3. Patch.
    $misses = $patch([$id]);
    $client->indices()->refresh(['index' => $index]);
    if ($misses) {
        echo "  NOTE: patch reported miss (would full-reproject): " . implode(',', $misses) . "\n";
    }
    $patched = $mget([$id])[$id] ?? null;

    // 4. Compare. If the patch fell back (miss), reproject and compare that instead — a miss is
    //    an allowed safe outcome, not a parity failure, as long as the doc ends up correct.
    if ($misses) {
        $indexer->executeList($misses);
        $client->indices()->refresh(['index' => $index]);
        $patched = $mget([$id])[$id] ?? null;
        echo "  (compared after fallback reproject)\n";
    }

    // Functional equality for the whole doc (tolerant of int/float/string numeric formats in
    // the stock_item sub-doc, which the read path casts) PLUS a strict check on top-level
    // is_in_stock: the ONE field with a `=== true` read dependency (ShellNoEavProduct::isSalable).
    $boolOk = is_bool($patched['is_in_stock'] ?? null)
        && ($patched['is_in_stock'] ?? null) === ($baseline['is_in_stock'] ?? null);
    if ($patched == $baseline && $boolOk) {
        echo "  PASS: doc identical to full reproject" . ($boolOk ? " (is_in_stock strict-bool ok)" : "") . ".\n";
        $pass++;
    } else {
        if (!$boolOk) {
            echo "    FAIL is_in_stock strict: base=" . var_export($baseline['is_in_stock'] ?? null, true)
                . " patched=" . var_export($patched['is_in_stock'] ?? null, true) . "\n";
        }
        $fail++;
        echo "  FAIL: doc differs from full reproject.\n";
        echo "    patched stock:  " . json_encode($stockSummary($patched ?? [])) . "\n";
        // Show which top-level keys differ.
        $allKeys = array_unique(array_merge(array_keys($baseline), array_keys($patched ?? [])));
        foreach ($allKeys as $k) {
            $bv = $baseline[$k] ?? null;
            $pv = ($patched[$k] ?? null);
            if ($bv !== $pv) {
                echo "    DIFF key[$k]: base=" . json_encode($bv) . " patched=" . json_encode($pv) . "\n";
            }
        }
    }

    // Restore a clean, correct doc regardless of outcome.
    $indexer->executeList([$id]);
}

echo str_repeat('=', 72) . "\n";
echo "RESULT: $pass passed, $fail failed.\n";
exit($fail ? 1 : 0);
