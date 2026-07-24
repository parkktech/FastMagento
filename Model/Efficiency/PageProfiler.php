<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Efficiency;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
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

    /** Dedicated, auto-provisioned customer used only to profile logged-in renders. */
    private const PROFILER_EMAIL = 'fastmagento-efficiency@example.com';
    private const PROFILER_PASSWORD = 'FmProfiler-2026!';

    /** customer-data sections a real logged-in shopper pulls on every page — where wishlist/compare N+1s hide. */
    private const CUSTOMER_SECTIONS = 'customer,cart,wishlist,compare-products,directory-data,last-ordered-items,persistent';

    private readonly string $root;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DirectoryList $directoryList,
        private readonly Shell $shell,
        private readonly DbLogParser $logParser,
        private readonly ModuleAttributor $attributor,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly EncryptorInterface $encryptor,
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

        // Logged-in pass — a real shopper renders per-customer-group pricing and the private
        // customer-data sections (wishlist/compare/reorder) a guest never triggers, where a separate
        // class of N+1s lives. Establish a session for a dedicated profiling customer and re-run the
        // hot paths + the customer-data endpoint through it. Best-effort: any failure leaves the guest
        // findings intact.
        try {
            foreach ($this->captureLoggedIn($host) as $finding) {
                $findings[] = $finding;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('FastMagento logged-in profile failed: ' . $e->getMessage());
        }

        usort($findings, static fn ($a, $b) => $b['loops'] <=> $a['loops']);
        return ['findings' => array_slice($findings, 0, 40), 'page_times' => $pageTimes];
    }

    /**
     * Profile the hot paths + customer-data endpoint as a logged-in customer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function captureLoggedIn(string $host): array
    {
        $customerId = $this->ensureProfilerCustomer();
        if ($customerId === null) {
            return [];
        }
        $jar = $this->login($host);
        if ($jar === null) {
            $this->logger->warning('FastMagento logged-in profile: could not establish a customer session.');
            return [];
        }

        $findings = [];
        try {
            // Same storefront hot paths, now rendered with a customer group + private blocks.
            foreach ($this->discoverPages() as $page) {
                if ($page['key'] === 'search') {
                    continue; // search is group-agnostic; skip to keep the logged-in pass lean
                }
                try {
                    [$queries] = $this->hit($host, $page['url'], $jar);
                } catch (\Throwable $e) {
                    continue;
                }
                $page['label'] .= ' (logged in)';
                foreach ($this->extract($queries, $page, 'logged_in') as $f) {
                    $findings[] = $f;
                }
            }

            // The customer-data endpoint every logged-in page hits — wishlist/compare/reorder loops live here.
            try {
                [$queries] = $this->hit(
                    $host,
                    '/customer/section/load/?sections=' . rawurlencode(self::CUSTOMER_SECTIONS) . '&update_section_id=false',
                    $jar
                );
                $section = ['key' => 'customer_data', 'label' => 'Customer data (logged in)'];
                foreach ($this->extract($queries, $section, 'logged_in') as $f) {
                    $findings[] = $f;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('FastMagento customer-data profile failed: ' . $e->getMessage());
            }
        } finally {
            @unlink($jar);
        }

        return $findings;
    }

    /**
     * Create (once) and return the id of the dedicated profiling customer. Never emails or touches a
     * real shopper; on any failure returns null so the logged-in pass is simply skipped.
     */
    private function ensureProfilerCustomer(): ?int
    {
        try {
            $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
            try {
                return (int) $this->customerRepository->get(self::PROFILER_EMAIL, $websiteId)->getId();
            } catch (NoSuchEntityException $e) {
                // fall through to create
            }
            $customer = $this->customerFactory->create();
            $customer->setWebsiteId($websiteId);
            $customer->setStoreId((int) $this->storeManager->getStore()->getId());
            $customer->setEmail(self::PROFILER_EMAIL);
            $customer->setFirstname('Efficiency');
            $customer->setLastname('Profiler');
            // save() with a pre-hashed password skips AccountManagement's welcome email entirely.
            $saved = $this->customerRepository->save(
                $customer,
                $this->encryptor->getHash(self::PROFILER_PASSWORD, true)
            );
            return (int) $saved->getId();
        } catch (\Throwable $e) {
            $this->logger->warning('FastMagento logged-in profile: could not provision profiler customer: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Log the profiling customer in over HTTP and return the path to a cookie jar carrying the
     * session, or null if login didn't take. Mirrors a real browser login (form key from the page).
     */
    private function login(string $host): ?string
    {
        $jar = $this->root . '/var/fastmagento/efficiency-session-' . getmypid() . '.txt';
        @unlink($jar);
        $hHost = 'Host: ' . $host;

        $html = $this->shell->execute(
            'curl -s -m 30 -c %s -H %s %s',
            [$jar, $hHost, 'http://127.0.0.1/customer/account/login/']
        );
        if (!preg_match('/name="form_key"[^>]*value="([^"]+)"/', $html, $m)) {
            return null;
        }
        $this->shell->execute(
            'curl -s -o /dev/null -m 30 -b %s -c %s -e %s --data-urlencode %s --data-urlencode %s --data-urlencode %s -H %s %s',
            [
                $jar, $jar, 'http://127.0.0.1/customer/account/login/',
                'login[username]=' . self::PROFILER_EMAIL,
                'login[password]=' . self::PROFILER_PASSWORD,
                'form_key=' . $m[1],
                $hHost, 'http://127.0.0.1/customer/account/loginPost/',
            ]
        );
        // Confirm the session is authenticated before profiling with it.
        $check = $this->shell->execute(
            'curl -s -m 30 -b %s -H %s %s',
            [$jar, $hHost, 'http://127.0.0.1/customer/section/load/?sections=customer']
        );
        if (!str_contains($check, self::PROFILER_EMAIL)) {
            @unlink($jar);
            return null;
        }
        return $jar;
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
    private function hit(string $host, string $path, ?string $cookieJar = null): array
    {
        $logPath = $this->root . '/var/debug/db.log';
        $sep = str_contains($path, '?') ? '&' : '?';
        $warm = 'http://127.0.0.1' . $path . $sep . 'fmcb=w';
        $meas = 'http://127.0.0.1' . $path . $sep . 'fmcb=m';

        // A logged-in hit carries the session cookie jar; a guest hit does not.
        $cookie = $cookieJar !== null ? '-b ' . escapeshellarg($cookieJar) . ' ' : '';

        $this->shell->execute("curl -s -o /dev/null -m 120 {$cookie}-H %s %s", ['Host: ' . $host, $warm]);
        @file_put_contents($logPath, '');
        $out = $this->shell->execute("curl -s -o /dev/null -w %s -m 120 {$cookie}-H %s %s", ['%{time_starttransfer}', 'Host: ' . $host, $meas]);
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
        // Several distinct line items — tax/subscription extensions loop PER item, so one item
        // hides the cost. This mirrors a real basket and makes the magnitude honest.
        $skus = $this->discoverSimpleSkus(8);
        if ($skus === []) {
            return [[], 0, false];
        }
        $base = 'http://127.0.0.1';
        $hHost = 'Host: ' . $host;
        $hJson = 'Content-Type: application/json';

        $cart = trim($this->shell->execute('curl -s -X POST -H %s -H %s %s', [$hHost, $hJson, "$base/rest/V1/guest-carts"]), "\" \n");
        if (strlen($cart) < 10) {
            return [[], 0, false];
        }
        foreach ($skus as $sku) {
            $item = '{"cartItem":{"sku":' . json_encode($sku) . ',"qty":1,"quote_id":' . json_encode($cart) . '}}';
            $this->shell->execute('curl -s -o /dev/null -X POST -H %s -H %s -d %s %s', [$hHost, $hJson, $item, "$base/rest/V1/guest-carts/$cart/items"]);
        }

        $body = '{"addressInformation":{"address":{"country_id":"US","region_id":12,"region_code":"CA","postcode":"90001","city":"Los Angeles","street":["1 Test St"],"firstname":"T","lastname":"T","telephone":"5555550100"},"shipping_method_code":"flatrate","shipping_carrier_code":"flatrate"}}';
        $url = "$base/rest/V1/guest-carts/$cart/totals-information";
        $this->shell->execute('curl -s -o /dev/null -X POST -H %s -H %s -d %s %s', [$hHost, $hJson, $body, $url]); // warm
        @file_put_contents($this->root . '/var/debug/db.log', '');
        $out = $this->shell->execute('curl -s -o /dev/null -w %s -X POST -H %s -H %s -d %s %s', ['%{time_total}', $hHost, $hJson, $body, $url]);
        $ttfb = (int) round(((float) trim($out)) * 1000);

        return [$this->logParser->parse($this->root . '/var/debug/db.log'), $ttfb, true];
    }

    /** @return string[] up to $n distinct in-stock simple SKUs */
    private function discoverSimpleSkus(int $n): array
    {
        $c = $this->resource->getConnection();
        $select = $c->select()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['sku'])
            ->join(['s' => $this->resource->getTableName('cataloginventory_stock_status')], 's.product_id = e.entity_id', [])
            ->where('e.type_id = ?', 'simple')
            ->where('s.stock_status = ?', 1)
            ->limit($n);
        return array_map('strval', $c->fetchCol($select));
    }

    /**
     * Group a page's third-party queries into (extension, class::method, table) loop counts.
     *
     * @param array<int, array<string, mixed>> $queries
     * @param array{key:string, label:string, url:string} $page
     * @return array<int, array<string, mixed>>
     */
    private function extract(array $queries, array $page, string $context = 'guest'): array
    {
        // Group per (extension · class::method). One method that loads a product touches many
        // tables — collapse those into a single hotspot whose "loops" is how many times the method
        // itself fired (the max over its tables), tagged with the heaviest table.
        $groups = [];
        foreach ($queries as $q) {
            if ($this->isCheckoutSessionBootstrap($q['frames'])) {
                continue; // harness artifact — see isCheckoutSessionBootstrap()
            }
            $hit = $this->attributor->attribute($q['frames']);
            if ($hit === null) {
                continue;
            }
            [$class, $method] = $this->businessCaller($q['frames'], $hit);
            $table = $q['table'] ?? '(n/a)';
            $key = $hit['module'] . '|' . $class . '|' . $method;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'extension' => $hit['title'],
                    'vendor'    => $hit['vendor'],
                    'page'      => $page['label'],
                    'page_key'  => $page['key'],
                    'context'   => $context,
                    'class'     => $class,
                    'method'    => $method,
                    'tables'    => [],
                ];
            }
            $groups[$key]['tables'][$table] = ($groups[$key]['tables'][$table] ?? 0) + 1;
        }

        $out = [];
        foreach ($groups as $g) {
            arsort($g['tables']);
            $loops = (int) reset($g['tables']);          // times the method fired (heaviest table)
            if ($loops < 3) {                            // real N+1 signal only
                continue;
            }
            $g['loops'] = $loops;
            $g['table'] = (string) key($g['tables']);
            unset($g['tables']);
            $out[] = $g;
        }
        return $out;
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
            $module = $this->attributor->resolveOwningModule($f['class'], $f['method']);
            if ($module === null || $this->attributor->isFirstParty($module['vendor'])) {
                continue;
            }
            if (!in_array($f['method'], self::NOISE_METHODS, true)) {
                return [$f['class'], $f['method']];
            }
        }
        return [$hit['class'], $hit['method']];
    }

    /**
     * True when a query was fired *inside* Checkout\Model\Session::getQuote() — the checkout
     * session's per-request quote bootstrap (load → collectTotals → persist).
     *
     * A warm browser session hydrates the checkout quote once and reuses it across page views, so
     * the customer-data "cart" section it pulls on every page does ~one address read and no writes.
     * The Monitor profiles headlessly (curl, no browser), and a headless session re-bootstraps the
     * quote on each hit — recollecting totals and re-persisting the quote (quote / quote_address /
     * inventory_pickup_location_quote_address writes plus repeated address reads). That volume is a
     * function of the harness, not something a real shopper hits, and it mis-attributes to whichever
     * customer-data section source (or afterGetSectionData plugin) happens to call getQuote() first.
     * Drop it so the endpoint reports only genuine per-render loops. Real cart/quote N+1s still
     * surface: per-item rendering (getRecentItems → getItemData) runs *after* getQuote() returns, so
     * those frames don't carry this signature, and the dedicated checkout-totals pass exercises the
     * quote through webapi (no checkout session) and is unaffected.
     *
     * @param array<int, array{class:string, method:string}> $frames
     */
    private function isCheckoutSessionBootstrap(array $frames): bool
    {
        foreach ($frames as $f) {
            if ($f['method'] === 'getQuote' && $f['class'] === 'Magento\\Checkout\\Model\\Session') {
                return true;
            }
        }
        return false;
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
