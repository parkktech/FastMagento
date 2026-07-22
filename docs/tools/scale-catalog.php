<?php

declare(strict_types=1);

/**
 * FastMagento — heavy-scale catalog generator (durable performance testing).
 *
 * Bulk-generates a very large apparel catalogue (configurable bra/swimsuit products with a big
 * colour × size matrix) directly via SQL, so the serving layer can be benchmarked against a
 * huge dataset. Models a large lingerie store: hundreds of thousands of simple products across
 * tens of thousands of colour options and every known size.
 *
 * SAFETY: refuses to run against any database whose name does not contain "scale" (so it can
 * never touch the live diyprod_db). Reads DB creds from app/etc/env.php, overriding the dbname.
 *
 *   php scale-catalog.php [--products=500000] [--colors=50000] [--children-per-config=120]
 *                         [--db=diyscale_db] [--template=4369] [--reset]
 *
 * Resumable: products are keyed by a SCALE-<n> SKU prefix; a re-run continues from the highest n.
 * --reset first deletes every previously generated SCALE-* product (leaves attribute options).
 */

$opt = getopt('', ['products::', 'colors::', 'children-per-config::', 'db::', 'template::', 'reset', 'colors-only', 'sizes-only']);
$TARGET_PRODUCTS = (int) ($opt['products'] ?? 500000);
$TARGET_COLORS   = (int) ($opt['colors'] ?? 50000);
$CHILDREN        = max(2, (int) ($opt['children-per-config'] ?? 120));
$DBNAME          = (string) ($opt['db'] ?? 'diyscale_db');
$TEMPLATE_PARENT = (int) ($opt['template'] ?? 4369);
$RESET           = isset($opt['reset']);

if (strpos($DBNAME, 'scale') === false) {
    fwrite(STDERR, "REFUSING: --db '{$DBNAME}' does not contain 'scale'. This tool only writes to a scale/test DB.\n");
    exit(2);
}

$root = dirname(__DIR__, 6);
$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO("mysql:host={$d['host']};dbname={$DBNAME};charset=utf8mb4", $d['username'], $d['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_LOCAL_INFILE => false,
]);
$pdo->exec("SET SESSION unique_checks=0, foreign_key_checks=0, sql_log_bin=0");

$t0 = microtime(true);
$log = static function (string $m) use ($t0): void {
    printf("[%6.1fs] %s\n", microtime(true) - $t0, $m);
};

// ── attribute ids ────────────────────────────────────────────────────────────────────────────
$attr = static function (string $code) use ($pdo): int {
    return (int) $pdo->query(
        "SELECT a.attribute_id FROM eav_attribute a JOIN eav_entity_type et ON et.entity_type_id=a.entity_type_id"
        . " WHERE et.entity_type_code='catalog_product' AND a.attribute_code=" . $pdo->quote($code)
    )->fetchColumn();
};
$A = [
    'name' => $attr('name'), 'url_key' => $attr('url_key'), 'status' => $attr('status'),
    'visibility' => $attr('visibility'), 'price' => $attr('price'), 'tax_class_id' => $attr('tax_class_id'),
    'color' => $attr('color'), 'size' => $attr('size'),
];
$ATTR_SET = (int) $pdo->query("SELECT attribute_set_id FROM catalog_product_entity WHERE entity_id={$TEMPLATE_PARENT}")->fetchColumn() ?: 4;
$CATEGORY = (int) $pdo->query("SELECT entity_id FROM catalog_category_entity WHERE level>=2 ORDER BY entity_id LIMIT 1")->fetchColumn() ?: 2;
$WEBSITES = array_map('intval', $pdo->query("SELECT website_id FROM store_website WHERE website_id>0")->fetchAll(PDO::FETCH_COLUMN));
$STORES   = array_map('intval', $pdo->query("SELECT store_id FROM store WHERE store_id>0")->fetchAll(PDO::FETCH_COLUMN));
$log("target: {$TARGET_PRODUCTS} simples · {$TARGET_COLORS} colours · {$CHILDREN}/config · db={$DBNAME} · set={$ATTR_SET} cat={$CATEGORY}");

// ── optional reset ───────────────────────────────────────────────────────────────────────────
if ($RESET) {
    $log('reset: deleting existing SCALE-* products…');
    $ids = $pdo->query("SELECT entity_id FROM catalog_product_entity WHERE sku LIKE 'SCALE-%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_chunk($ids, 5000) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        // entity_id-keyed tables (the base entity + EAV value tables)
        foreach (['catalog_product_entity', 'catalog_product_entity_varchar',
                  'catalog_product_entity_int', 'catalog_product_entity_decimal'] as $tbl) {
            $pdo->exec("DELETE FROM {$tbl} WHERE entity_id IN ({$in})");
        }
        // product_id-keyed tables
        foreach (['cataloginventory_stock_item', 'cataloginventory_stock_status', 'catalog_product_website',
                  'catalog_category_product', 'catalog_product_super_attribute'] as $tbl) {
            $pdo->exec("DELETE FROM {$tbl} WHERE product_id IN ({$in})");
        }
        // relation tables (either side)
        $pdo->exec("DELETE FROM catalog_product_super_link WHERE parent_id IN ({$in}) OR product_id IN ({$in})");
        $pdo->exec("DELETE FROM catalog_product_relation WHERE parent_id IN ({$in}) OR child_id IN ({$in})");
    }
    $pdo->exec("DELETE FROM url_rewrite WHERE request_path LIKE 'scale-%' AND entity_type='product'");
    $log('reset done (' . count($ids) . ' products removed)');
}

// ── 1. sizes ─────────────────────────────────────────────────────────────────────────────────
$sizeIds = ensureOptions($pdo, $A['size'], $STORES, buildSizeLabels(), $log, 'size');
if (isset($opt['sizes-only'])) { $log('sizes-only: done'); exit(0); }

// ── 2. colours ───────────────────────────────────────────────────────────────────────────────
$colorIds = ensureOptions($pdo, $A['color'], $STORES, buildColorLabels($TARGET_COLORS), $log, 'colour', $TARGET_COLORS);
if (isset($opt['colors-only'])) { $log('colors-only: done'); exit(0); }

// ── 3. products ──────────────────────────────────────────────────────────────────────────────
$existing = (int) $pdo->query("SELECT COUNT(*) FROM catalog_product_entity WHERE sku LIKE 'SCALE-%' AND type_id='simple'")->fetchColumn();
$startIdx = (int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(sku,'-',2),'-',-1) AS UNSIGNED)),0) FROM catalog_product_entity WHERE sku LIKE 'SCALE-C%'")->fetchColumn();
$nextId = (int) $pdo->query("SELECT MAX(entity_id) FROM catalog_product_entity")->fetchColumn() + 1;
$log("already generated: {$existing} simples; resuming configs from index " . ($startIdx + 1));

$colorValues = array_values($colorIds);
$sizeValues  = array_values($sizeIds);
$made = $existing;
$configIdx = $startIdx;
$sizesPerConfig = min(count($sizeValues), max(2, (int) ceil($CHILDREN / 8))); // ~8 colours per size row
$colorsPerConfig = (int) max(1, ceil($CHILDREN / $sizesPerConfig));

while ($made < $TARGET_PRODUCTS) {
    $configIdx++;
    $take = min($CHILDREN, $TARGET_PRODUCTS - $made);
    $rows = makeConfig($pdo, $nextId, $configIdx, $take, $colorValues, $sizeValues, $colorsPerConfig, $sizesPerConfig, $A, $ATTR_SET, $CATEGORY, $WEBSITES, $STORES);
    $nextId = $rows['nextId'];
    $made += $rows['children'];
    if ($configIdx % 50 === 0) {
        $log(sprintf('… %d / %d simples (%d configs)', $made, $TARGET_PRODUCTS, $configIdx));
    }
}
$log(sprintf('DONE: %d simple products across %d configurables generated in db=%s', $made, $configIdx, $DBNAME));
$log("Next: point Magento at {$DBNAME}, run indexers (incl. fastmagento_product) and benchmark.");


// ══════════════════════════════════════════════════════════════════════════════════════════════
// helpers
// ══════════════════════════════════════════════════════════════════════════════════════════════

/**
 * Ensure the given labels exist as options for $attributeId; returns [label => option_id] for all
 * (existing + created). Bulk-inserts missing options + admin/store option_value rows.
 *
 * @param string[] $labels
 * @param int[] $stores
 * @return array<string,int>
 */
function ensureOptions(PDO $pdo, int $attributeId, array $stores, array $labels, callable $log, string $what, int $cap = 0): array
{
    $existing = [];
    $q = $pdo->prepare("SELECT o.option_id, v.value FROM eav_attribute_option o JOIN eav_attribute_option_value v ON v.option_id=o.option_id AND v.store_id=0 WHERE o.attribute_id=?");
    $q->execute([$attributeId]);
    foreach ($q->fetchAll(PDO::FETCH_KEY_PAIR) as $oid => $val) { $existing[(string) $val] = (int) $oid; }

    $missing = [];
    foreach ($labels as $label) {
        if (!isset($existing[$label])) { $missing[] = $label; }
    }
    if ($cap > 0 && (count($existing) + count($missing)) > $cap) {
        $missing = array_slice($missing, 0, max(0, $cap - count($existing)));
    }
    $log("{$what}: " . count($existing) . " existing, inserting " . count($missing) . ' …');

    foreach (array_chunk($missing, 2000) as $chunk) {
        $pdo->beginTransaction();
        // options
        $vals = implode(',', array_fill(0, count($chunk), "({$attributeId},0,0)"));
        $pdo->exec("INSERT INTO eav_attribute_option (attribute_id, sort_order, group_id) VALUES {$vals}");
        $firstId = (int) $pdo->lastInsertId();
        // option_value rows for store 0 + each store view
        $ph = [];
        $args = [];
        $i = 0;
        foreach ($chunk as $label) {
            $oid = $firstId + $i;
            foreach (array_merge([0], $stores) as $sid) {
                $ph[] = '(?,?,?)';
                array_push($args, $oid, $sid, $label);
            }
            $existing[$label] = $oid;
            $i++;
        }
        $ins = $pdo->prepare("INSERT INTO eav_attribute_option_value (option_id, store_id, value) VALUES " . implode(',', $ph));
        $ins->execute($args);
        $pdo->commit();
    }
    // return only the requested labels (present now)
    $out = [];
    foreach ($labels as $label) {
        if (isset($existing[$label])) { $out[$label] = $existing[$label]; }
    }
    return $out;
}

/**
 * Create one configurable parent + $childCount simple children, fully wired (EAV, stock, website,
 * category, super attr/link/relation, url_rewrite). Returns [nextId, children].
 *
 * @param int[] $colorValues option ids   @param int[] $sizeValues option ids
 * @param array<string,int> $A attribute ids   @param int[] $websites   @param int[] $stores
 * @return array{nextId:int, children:int}
 */
function makeConfig(PDO $pdo, int $nextId, int $configIdx, int $childCount, array $colorValues, array $sizeValues, int $colorsPerConfig, int $sizesPerConfig, array $A, int $set, int $cat, array $websites, array $stores): array
{
    // deterministic-ish pseudo-random subset (varies by index, no Math.random needed)
    $colors = pick($colorValues, $colorsPerConfig, $configIdx * 7);
    $sizes  = pick($sizeValues, $sizesPerConfig, $configIdx * 13);

    $pdo->beginTransaction();
    $parentId = $nextId++;
    $psku = "SCALE-C{$configIdx}";
    $pName = "Scale Test Bra {$configIdx}";
    $pUrlKey = strtolower($psku) . '-' . $parentId;

    // build child list up to childCount
    $children = [];
    foreach ($sizes as $s) {
        foreach ($colors as $c) {
            if (count($children) >= $childCount) { break 2; }
            $children[] = ['id' => $nextId++, 'color' => $c, 'size' => $s];
        }
    }

    // entities
    $entRows = [[$parentId, $set, 'configurable', $psku, 1, 1]];
    foreach ($children as $ch) {
        $entRows[] = [$ch['id'], $set, 'simple', "{$psku}-{$ch['color']}-{$ch['size']}", 0, 0];
    }
    bulkInsert($pdo, 'catalog_product_entity',
        ['entity_id', 'attribute_set_id', 'type_id', 'sku', 'has_options', 'required_options'], $entRows,
        ", created_at=NOW(), updated_at=NOW()");

    // varchar: name + url_key (store 0)
    $vc = [];
    $vc[] = [$A['name'], 0, $parentId, $pName];
    $vc[] = [$A['url_key'], 0, $parentId, $pUrlKey];
    foreach ($children as $ch) {
        $vc[] = [$A['name'], 0, $ch['id'], "{$pName} - {$ch['color']}/{$ch['size']}"];
        $vc[] = [$A['url_key'], 0, $ch['id'], strtolower($psku) . "-{$ch['color']}-{$ch['size']}-{$ch['id']}"];
    }
    bulkInsert($pdo, 'catalog_product_entity_varchar', ['attribute_id', 'store_id', 'entity_id', 'value'], $vc);

    // int: status=1, visibility (parent 4, child 1), color/size on children
    $iv = [];
    $iv[] = [$A['status'], 0, $parentId, 1];
    $iv[] = [$A['visibility'], 0, $parentId, 4];
    foreach ($children as $ch) {
        $iv[] = [$A['status'], 0, $ch['id'], 1];
        $iv[] = [$A['visibility'], 0, $ch['id'], 1];
        $iv[] = [$A['color'], 0, $ch['id'], $ch['color']];
        $iv[] = [$A['size'], 0, $ch['id'], $ch['size']];
    }
    bulkInsert($pdo, 'catalog_product_entity_int', ['attribute_id', 'store_id', 'entity_id', 'value'], $iv);

    // decimal: price
    $price = 19.99 + ($configIdx % 40);
    $dv = [[$A['price'], 0, $parentId, $price]];
    foreach ($children as $ch) { $dv[] = [$A['price'], 0, $ch['id'], $price]; }
    bulkInsert($pdo, 'catalog_product_entity_decimal', ['attribute_id', 'store_id', 'entity_id', 'value'], $dv);

    // stock (children carry real stock; parent salable via children)
    $si = []; $ss = [];
    foreach (array_merge([['id' => $parentId, 'in' => 1]], array_map(fn($c) => ['id' => $c['id'], 'in' => 1], $children)) as $p) {
        $qty = 100;
        $si[] = [$p['id'], 1, 0, $qty, $p['in'], 1, 1];
        $ss[] = [$p['id'], 0, 1, $qty, $p['in']];
    }
    bulkInsert($pdo, 'cataloginventory_stock_item', ['product_id', 'stock_id', 'website_id', 'qty', 'is_in_stock', 'manage_stock', 'use_config_manage_stock'], $si);
    bulkInsert($pdo, 'cataloginventory_stock_status', ['product_id', 'website_id', 'stock_id', 'qty', 'stock_status'], $ss);

    // website links
    $wv = [];
    foreach (array_merge([$parentId], array_column($children, 'id')) as $pid) {
        foreach ($websites as $w) { $wv[] = [$pid, $w]; }
    }
    bulkInsert($pdo, 'catalog_product_website', ['product_id', 'website_id'], $wv);

    // category link (parent + children into one category)
    $cv = [];
    foreach (array_merge([$parentId], array_column($children, 'id')) as $pid) { $cv[] = [$cat, $pid, 1]; }
    bulkInsert($pdo, 'catalog_category_product', ['category_id', 'product_id', 'position'], $cv);

    // super attribute (parent) — color then size
    bulkInsert($pdo, 'catalog_product_super_attribute', ['product_id', 'attribute_id', 'position'],
        [[$parentId, $A['color'], 0], [$parentId, $A['size'], 1]]);

    // super link + relation
    $sl = []; $rel = [];
    foreach ($children as $ch) { $sl[] = [$ch['id'], $parentId]; $rel[] = [$parentId, $ch['id']]; }
    bulkInsert($pdo, 'catalog_product_super_link', ['product_id', 'parent_id'], $sl);
    bulkInsert($pdo, 'catalog_product_relation', ['parent_id', 'child_id'], $rel);

    // url_rewrite for the parent (per store)
    $ur = [];
    foreach ($stores as $sid) {
        $ur[] = ['product', $parentId, "{$pUrlKey}.html", "catalog/product/view/id/{$parentId}", 0, $sid, 1];
    }
    bulkInsert($pdo, 'url_rewrite', ['entity_type', 'entity_id', 'request_path', 'target_path', 'redirect_type', 'store_id', 'is_autogenerated'], $ur);

    $pdo->commit();
    return ['nextId' => $nextId, 'children' => count($children)];
}

/**
 * Multi-row INSERT (chunked at 1000 rows). $extra is appended raw to each VALUES-less clause
 * (used for created_at/updated_at=NOW()).
 *
 * @param string[] $cols  @param array<int,array<int,mixed>> $rows
 */
function bulkInsert(PDO $pdo, string $table, array $cols, array $rows, string $extra = ''): void
{
    if (!$rows) { return; }
    $colList = '`' . implode('`,`', $cols) . '`';
    foreach (array_chunk($rows, 1000) as $chunk) {
        $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $sql = "INSERT INTO {$table} ({$colList}) VALUES " . implode(',', array_fill(0, count($chunk), $ph));
        if ($extra !== '') {
            // rewrite: MySQL can't mix VALUES with SET; emulate created/updated via ON slots instead.
            // Simpler: append columns with NOW() by extending each row — handled by caller only for entity.
            $sql = "INSERT INTO {$table} ({$colList}, created_at, updated_at) VALUES "
                . implode(',', array_fill(0, count($chunk), '(' . implode(',', array_fill(0, count($cols), '?')) . ',NOW(),NOW())'));
        }
        $args = [];
        foreach ($chunk as $r) { foreach ($r as $v) { $args[] = $v; } }
        $pdo->prepare($sql)->execute($args);
    }
}

/**
 * Deterministic pseudo-random subset of $n items from $arr, seeded by $seed (no rng needed).
 *
 * @param int[] $arr
 * @return int[]
 */
function pick(array $arr, int $n, int $seed): array
{
    $count = count($arr);
    $n = min($n, $count);
    $out = [];
    $step = max(1, (int) ($count / max(1, $n)));
    $pos = $seed % $count;
    for ($i = 0; $i < $n; $i++) {
        $out[] = $arr[$pos % $count];
        $pos += $step + ($i % 3);
    }
    return array_values(array_unique($out)) ?: [$arr[0]];
}

/** @return string[] every known apparel + bra size label */
function buildSizeLabels(): array
{
    $letter = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', '3XL', '4XL', '5XL', '6XL',
        'Extra Small', 'Small', 'Medium', 'Large', 'Extra Large', 'Petite', 'Plus'];
    $numeric = [];
    for ($i = 0; $i <= 30; $i += 2) { $numeric[] = (string) $i; }        // 0..30
    $bands = range(28, 56, 2);
    $cups = ['AA', 'A', 'B', 'C', 'D', 'DD', 'DDD', 'E', 'F', 'FF', 'G', 'GG', 'H', 'HH', 'I', 'J', 'JJ', 'K'];
    $bra = [];
    foreach ($bands as $b) { foreach ($cups as $c) { $bra[] = "{$b}{$c}"; } }
    return array_values(array_unique(array_merge($letter, $numeric, $bra)));
}

/** @return string[] up to $target unique colour/pattern labels */
function buildColorLabels(int $target): array
{
    $base = ['Black', 'White', 'Red', 'Blue', 'Green', 'Yellow', 'Pink', 'Purple', 'Orange', 'Brown',
        'Gray', 'Navy', 'Teal', 'Maroon', 'Beige', 'Coral', 'Turquoise', 'Lavender', 'Mint', 'Peach',
        'Burgundy', 'Ivory', 'Charcoal', 'Gold', 'Silver', 'Rose', 'Emerald', 'Sapphire', 'Plum', 'Olive',
        'Cyan', 'Magenta', 'Crimson', 'Indigo', 'Violet', 'Salmon', 'Khaki', 'Tan', 'Aqua', 'Fuchsia'];
    $patterns = ['Stripes', 'Plaid', 'Polka Dot', 'Colorblock', 'Ombre', 'Gradient', 'Camo', 'Tie-Dye',
        'Floral', 'Lace', 'Mesh', 'Marble', 'Chevron', 'Houndstooth', 'Gingham'];
    $prints = ['Tiger', 'Leopard', 'Zebra', 'Snake', 'Cheetah', 'Giraffe', 'Cow', 'Butterfly', 'Galaxy', 'Tropical'];
    $shades = ['Light', 'Dark', 'Deep', 'Pale', 'Bright', 'Muted', 'Neon', 'Pastel', 'Hot', 'Dusty'];

    $out = [];
    foreach ($base as $c) { $out[$c] = true; }
    foreach ($shades as $sh) { foreach ($base as $c) { $out["{$sh} {$c}"] = true; } }
    foreach ($base as $c) { foreach ($prints as $p) { $out["{$c} {$p} Print"] = true; } }
    // two-colour × pattern combos (the bulk of the 50k)
    foreach ($base as $c1) {
        foreach ($base as $c2) {
            if ($c1 === $c2) { continue; }
            foreach ($patterns as $p) {
                $out["{$c1}/{$c2} {$p}"] = true;
                if (count($out) >= $target) { break 3; }
            }
        }
    }
    $labels = array_keys($out);
    // pad deterministically if still short
    for ($n = 1; count($labels) < $target; $n++) {
        $labels[] = 'Custom Pattern ' . $n;
    }
    return array_slice($labels, 0, $target);
}
