<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Efficiency;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Shell;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders the real storefront pages over HTTP (with db_logger on) and mines the captured SQL for
 * per-request N+1 loops attributed to third-party extensions — the block-render-time overhead the
 * in-process scenarios can't see (e.g. Webkul re-loading seller data ~87x/PDP inside its blocks).
 *
 * Produces developer-ready findings: {extension, page, class, method, loops, table}. "loops" is the
 * number of times the same (class::method → table) query fired in one page render — the signal that
 * says "this method is looping; go look here".
 */
class PageProfiler
{
    /** Generic ORM/collection methods that hide the real business caller. */
    private const NOISE_METHODS = [
        'load', 'getSize', 'getData', 'getSelect', 'getConnection', 'fetchItem', 'fetchAll',
        'fetchOne', 'fetchCol', 'fetchPairs', 'getIterator', 'getFirstItem', 'addFieldToFilter',
        '_renderFilters', '_renderFiltersBefore', '_beforeLoad', '_afterLoad', 'clear', 'count',
        '__construct', 'getAllIds', 'getItems', 'toArray', 'getColumnValues', 'loadData',
    ];

    private readonly string $root;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DirectoryList $directoryList,
        private readonly Shell $shell,
        private readonly DbLogParser $logParser,
        private readonly ModuleAttributor $attributor,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
        $this->root = $this->directoryList->getRoot();
    }

    /**
     * @return array{findings:array<int,array<string,mixed>>, page_times:array<int,array<string,mixed>>}
     */
    public function capture(): array
    {
        $host = $this->storeHost();
        if ($host === null) {
            return ['findings' => [], 'page_times' => []];
        }

        $findings = [];
        $pageTimes = [];
        foreach ($this->discoverPages() as $page) {
            try {
                [$queries, $ttfb] = $this->hit($host, $page['url']);
            } catch (\Throwable $e) {
                $this->logger->warning("FastMagento page profile '{$page['key']}' failed: " . $e->getMessage());
                continue;
            }
            $pageTimes[] = ['page' => $page['label'], 'key' => $page['key'], 'ms' => $ttfb];
            foreach ($this->extract($queries, $page) as $finding) {
                $findings[] = $finding;
            }
        }

        // Checkout totals — the path Stripe (and other tax/totals extensions) are expensive on.
        try {
            [$queries, $ttfb, $ok] = $this->captureCheckout($host);
            if ($ok) {
                $pageTimes[] = ['page' => 'Checkout (totals)', 'key' => 'checkout', 'ms' => $ttfb];
                foreach ($this->extract($queries, ['key' => 'checkout', 'label' => 'Checkout']) as $finding) {
                    $findings[] = $finding;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('FastMagento checkout profile failed: ' . $e->getMessage());
        }

        usort($findings, static fn ($a, $b) => $b['loops'] <=> $a['loops']);
        return ['findings' => array_slice($findings, 0, 30), 'page_times' => $pageTimes];
    }

    /**
     * @return array<int, array{key:string, label:string, url:string}>
     */
    private function discoverPages(): array
    {
        $pages = [['key' => 'home', 'label' => 'Home', 'url' => '/']];
        if ($pdp = $this->firstUrl('product')) {
            $pages[] = ['key' => 'pdp', 'label' => 'Product page (PDP)', 'url' => '/' . ltrim($pdp, '/')];
        }
        if ($plp = $this->firstUrl('category')) {
            $pages[] = ['key' => 'plp', 'label' => 'Category (PLP)', 'url' => '/' . ltrim($plp, '/')];
        }
        $pages[] = ['key' => 'search', 'label' => 'Search', 'url' => '/catalogsearch/result/?q=' . $this->searchTerm()];
        return $pages;
    }

    private function firstUrl(string $entityType): ?string
    {
        $c = $this->resource->getConnection();
        $select = $c->select()
            ->from($this->resource->getTableName('url_rewrite'), ['request_path'])
            ->where('entity_type = ?', $entityType)
            ->where('redirect_type = ?', 0)
            ->where('request_path LIKE ?', '%.html')
            ->limit(1);
        $v = $c->fetchOne($select);
        return $v !== false && $v !== '' ? (string) $v : null;
    }

    private function searchTerm(): string
    {
        $c = $this->resource->getConnection();
        $select = $c->select()
            ->from(['v' => $this->resource->getTableName('catalog_product_entity_varchar')], ['value'])
            ->join(['a' => $this->resource->getTableName('eav_attribute')], 'a.attribute_id = v.attribute_id', [])
            ->where('a.attribute_code = ?', 'name')
            ->where('v.value != ?', '')
            ->limit(1);
        $v = (string) $c->fetchOne($select);
        return preg_match('/[A-Za-z]{4,}/', $v, $m) ? $m[0] : 'shirt';
    }

    /**
     * Cache-busted double hit (warm, then measure) so we capture a cold full render.
     *
     * @return array<int, array{sql:string, table:?string, time:float, frames:array}>
     */
    private function hit(string $host, string $path): array
    {
        $logPath = $this->root . '/var/debug/db.log';
        $sep = str_contains($path, '?') ? '&' : '?';
        $warm = 'http://127.0.0.1' . $path . $sep . 'fmcb=w';
        $meas = 'http://127.0.0.1' . $path . $sep . 'fmcb=m';

        $this->shell->execute('curl -s -o /dev/null -m 120 -H %s %s', ['Host: ' . $host, $warm]);
        @file_put_contents($logPath, '');
        $out = $this->shell->execute('curl -s -o /dev/null -w %s -m 120 -H %s %s', ['%{time_starttransfer}', 'Host: ' . $host, $meas]);
        $ttfb = (int) round(((float) trim($out)) * 1000);

        return [$this->logParser->parse($logPath), $ttfb];
    }

    /**
     * Build a tiny guest cart (read-only totals, no order placed) and profile the checkout totals
     * path — where tax/subscription extensions (Stripe, …) loop per line item.
     *
     * @return array{0:array, 1:int, 2:bool} [queries, ttfb_ms, ok]
     */
    private function captureCheckout(string $host): array
    {
        $sku = $this->discoverSimpleSku();
        if ($sku === null) {
            return [[], 0, false];
        }
        $base = 'http://127.0.0.1';
        $hHost = 'Host: ' . $host;
        $hJson = 'Content-Type: application/json';

        $cart = trim($this->shell->execute('curl -s -X POST -H %s -H %s %s', [$hHost, $hJson, "$base/rest/V1/guest-carts"]), "\" \n");
        if (strlen($cart) < 10) {
            return [[], 0, false];
        }
        $item = '{"cartItem":{"sku":' . json_encode($sku) . ',"qty":2,"quote_id":' . json_encode($cart) . '}}';
        $this->shell->execute('curl -s -o /dev/null -X POST -H %s -H %s -d %s %s', [$hHost, $hJson, $item, "$base/rest/V1/guest-carts/$cart/items"]);

        $body = '{"addressInformation":{"address":{"country_id":"US","region_id":12,"region_code":"CA","postcode":"90001","city":"Los Angeles","street":["1 Test St"],"firstname":"T","lastname":"T","telephone":"5555550100"},"shipping_method_code":"flatrate","shipping_carrier_code":"flatrate"}}';
        $url = "$base/rest/V1/guest-carts/$cart/totals-information";
        $this->shell->execute('curl -s -o /dev/null -X POST -H %s -H %s -d %s %s', [$hHost, $hJson, $body, $url]); // warm
        @file_put_contents($this->root . '/var/debug/db.log', '');
        $out = $this->shell->execute('curl -s -o /dev/null -w %s -X POST -H %s -H %s -d %s %s', ['%{time_total}', $hHost, $hJson, $body, $url]);
        $ttfb = (int) round(((float) trim($out)) * 1000);

        return [$this->logParser->parse($this->root . '/var/debug/db.log'), $ttfb, true];
    }

    private function discoverSimpleSku(): ?string
    {
        $c = $this->resource->getConnection();
        $select = $c->select()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['sku'])
            ->join(['s' => $this->resource->getTableName('cataloginventory_stock_status')], 's.product_id = e.entity_id', [])
            ->where('e.type_id = ?', 'simple')
            ->where('s.stock_status = ?', 1)
            ->limit(1);
        $v = $c->fetchOne($select);
        return $v !== false && $v !== '' ? (string) $v : null;
    }

    /**
     * Group a page's third-party queries into (extension, class::method, table) loop counts.
     *
     * @param array<int, array<string, mixed>> $queries
     * @param array{key:string, label:string, url:string} $page
     * @return array<int, array<string, mixed>>
     */
    private function extract(array $queries, array $page): array
    {
        $groups = [];
        foreach ($queries as $q) {
            $hit = $this->attributor->attribute($q['frames']);
            if ($hit === null) {
                continue;
            }
            [$class, $method] = $this->businessCaller($q['frames'], $hit);
            $table = $q['table'] ?? '(n/a)';
            $key = $hit['module'] . '|' . $class . '|' . $method . '|' . $table;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'extension' => $hit['title'],
                    'vendor'    => $hit['vendor'],
                    'page'      => $page['label'],
                    'page_key'  => $page['key'],
                    'class'     => $class,
                    'method'    => $method,
                    'table'     => $table,
                    'loops'     => 0,
                ];
            }
            $groups[$key]['loops']++;
        }

        // Only surface real loops (>=3 identical fires) — that's the actionable N+1 signal.
        return array_values(array_filter($groups, static fn ($g) => $g['loops'] >= 3));
    }

    /**
     * The most useful (class, method) to show a developer: the deepest third-party frame whose
     * method isn't generic ORM plumbing. Falls back to the attributor's frame.
     *
     * @param array<int, array{class:string, method:string}> $frames
     * @param array{class:string, method:string} $hit
     * @return array{0:string, 1:string}
     */
    private function businessCaller(array $frames, array $hit): array
    {
        foreach ($frames as $f) {
            $module = $this->attributor->resolveModule($f['class']);
            if ($module === null || $this->attributor->isFirstParty($module['vendor'])) {
                continue;
            }
            if (!in_array($f['method'], self::NOISE_METHODS, true)) {
                return [$f['class'], $f['method']];
            }
        }
        return [$hit['class'], $hit['method']];
    }

    private function storeHost(): ?string
    {
        try {
            $base = $this->storeManager->getStore()->getBaseUrl();
            $host = parse_url($base, PHP_URL_HOST);
            return $host ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
