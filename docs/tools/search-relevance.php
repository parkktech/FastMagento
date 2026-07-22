<?php

declare(strict_types=1);

/**
 * FastMagento search-relevance harness.
 *
 * Runs a set of golden queries through the REAL InstantSearch query builder and prints the
 * ranked top-N with `_score`, plus a light PASS/FAIL check against expectations, so relevance
 * changes are measured — not guessed. Build once, run before/after every ranking change.
 *
 *   php app/code/ParkkTech/FastMagento/docs/tools/search-relevance.php [query ...]
 *   php app/code/ParkkTech/FastMagento/docs/tools/search-relevance.php --file=golden-queries.json --top=10
 *
 * With no query args it loads golden-queries.json from this directory. Pass one or more raw
 * queries to test them ad hoc instead.
 */

use ParkkTech\FastMagento\Model\Search\InstantSearch;
use ParkkTech\FastMagento\Model\Search\RelevanceConfig;

$root = dirname(__DIR__, 6);                     // app/code/ParkkTech/FastMagento/docs/tools -> BP
require $root . '/app/bootstrap.php';

$args = array_slice($argv, 1);
$top = 10;
$file = __DIR__ . '/golden-queries.json';
$queries = [];
foreach ($args as $arg) {
    if (preg_match('/^--top=(\d+)$/', $arg, $m)) {
        $top = max(1, (int) $m[1]);
    } elseif (preg_match('/^--file=(.+)$/', $arg, $m)) {
        $file = $m[1][0] === '/' ? $m[1] : __DIR__ . '/' . $m[1];
    } else {
        $queries[] = ['query' => $arg];
    }
}
if (!$queries) {
    $decoded = json_decode((string) @file_get_contents($file), true);
    $queries = is_array($decoded) ? $decoded : [];
    if (!$queries) {
        fwrite(STDERR, "No queries given and could not read {$file}\n");
        exit(1);
    }
}

$bootstrap = \Magento\Framework\App\Bootstrap::create($root, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
/** @var InstantSearch $search */
$search = $om->get(InstantSearch::class);
/** @var RelevanceConfig $rel */
$rel = $om->get(RelevanceConfig::class);

$c = static fn (string $code, string $s): string => (getenv('NO_COLOR') ? $s : "\033[{$code}m{$s}\033[0m");

echo "\n" . $c('1', 'FastMagento search-relevance harness') . "\n";
echo 'operator=' . $c('36', $rel->getSearchOperator())
    . '  phrase_boost=' . $c('36', (string) $rel->getPhraseMatchBoost())
    . '  typo=' . $c('36', $rel->isTypoToleranceEnabled() ? 'on' : 'off')
    . '  ai_keywords=' . $c('36', $rel->isSearchKeywordsEnabled() ? 'on' : 'off')
    . '  fields=[' . implode(', ', $rel->getBoostedFields()) . "]\n";

$totalChecks = 0;
$passedChecks = 0;

foreach ($queries as $spec) {
    $q = (string) ($spec['query'] ?? '');
    if ($q === '') {
        continue;
    }
    $res = $search->debugSearch($q, $top);
    echo "\n" . $c('1;33', '▸ "' . $q . '"') . '  (' . $res['total'] . " match" . ($res['total'] === 1 ? '' : 'es') . ")\n";
    if (!empty($spec['note'])) {
        echo '  ' . $c('90', $spec['note']) . "\n";
    }

    $names = [];
    foreach ($res['hits'] as $i => $hit) {
        $names[] = mb_strtolower($hit['name']);
        printf("  %2d. %s  %s  %s\n",
            $i + 1,
            $c('32', sprintf('%6.2f', $hit['score'])),
            str_pad(mb_substr($hit['name'], 0, 60), 60),
            $c('90', $hit['sku'])
        );
    }
    if (!$res['hits']) {
        echo '  ' . $c('31', '(no results)') . "\n";
    }

    // Light expectation checks.
    $top3 = array_slice($names, 0, 3);
    if (!empty($spec['expect_top_any'])) {
        $totalChecks++;
        $hit = false;
        foreach ((array) $spec['expect_top_any'] as $needle) {
            foreach ($top3 as $name) {
                if (mb_stripos($name, (string) $needle) !== false) {
                    $hit = true;
                    break 2;
                }
            }
        }
        echo '  ' . ($hit ? $c('32', '✓') : $c('31', '✗'))
            . ' top-3 contains any of [' . implode(', ', (array) $spec['expect_top_any']) . "]\n";
        $passedChecks += $hit ? 1 : 0;
    }
    if (!empty($spec['avoid_top3'])) {
        $totalChecks++;
        $bad = false;
        foreach ((array) $spec['avoid_top3'] as $needle) {
            foreach ($top3 as $name) {
                if (mb_stripos($name, (string) $needle) !== false) {
                    $bad = true;
                    break 2;
                }
            }
        }
        echo '  ' . ($bad ? $c('31', '✗') : $c('32', '✓'))
            . ' top-3 avoids [' . implode(', ', (array) $spec['avoid_top3']) . "]\n";
        $passedChecks += $bad ? 0 : 1;
    }
}

echo "\n" . $c('1', "checks: {$passedChecks}/{$totalChecks} passed") . "\n\n";
exit($totalChecks > 0 && $passedChecks < $totalChecks ? 2 : 0);
