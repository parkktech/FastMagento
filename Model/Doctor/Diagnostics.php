<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Doctor;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Magento\Framework\Search\EngineResolverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Model\Config\Source\FacetAttributes;
use ParkkTech\FastMagento\Model\OpenSearch\IndexSettings;
use ParkkTech\FastMagento\Model\OptionDictionary;
use ParkkTech\FastMagento\Setup\Patch\Data\InitializeIndexers;

/**
 * Everything that has to be true for FastMagento to actually serve, checked in one pass.
 *
 * This exists because of a single recurring support shape: FastMagento's failure modes do not
 * throw. A missing index, an unbuilt option dictionary, an index prefix shared with another
 * store, a facet attribute Magento will not aggregate, a theme with no RequireJS — every one of
 * those leaves the storefront returning HTTP 200 with a feature quietly missing. Each check below
 * corresponds to a real failure observed on a real install.
 *
 * Read-only: it inspects and reports, never repairs.
 */
class Diagnostics
{
    private const G_CLUSTER = 'Cluster';
    private const G_INDEX = 'Indices';
    private const G_INDEXER = 'Indexers';
    private const G_CRON = 'Cron';
    private const G_FACETS = 'Facets';
    private const G_THEME = 'Theme';
    private const G_CHECKOUT = 'Checkout';
    private const G_PLP = 'Listing';
    private const G_LOCK = 'Locking';
    private const G_PHP = 'PHP';
    private const G_COMMERCE = 'Commerce';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resource,
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly IndexerInterfaceFactory $indexerFactory,
        private readonly FacetAttributes $facetAttributes,
        private readonly OptionDictionary $optionDictionary,
        private readonly IndexSettings $indexSettings,
        private readonly \Magento\Framework\Module\Manager $moduleManager,
        private readonly \ParkkTech\FastMagento\Model\Db\EntityLink $entityLink,
        private readonly \Magento\Framework\View\DesignInterface $design,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \Magento\Framework\View\Design\Theme\ThemeProviderInterface $themeProvider,
        private readonly \Magento\Framework\ObjectManager\ConfigLoaderInterface $configLoader,
        private readonly \Magento\Framework\Filesystem $filesystem,
        private readonly \ParkkTech\FastMagento\Model\Plp\FallbackRecorder $plpFallbackRecorder,
        private readonly \Magento\Framework\App\DeploymentConfig $deploymentConfig,
        /** @var CheckProviderInterface[] DI pool; other modules register their doctor sections here. Empty in core. */
        private readonly array $checkProviders = []
    ) {
    }

    /**
     * Active frontend theme path (e.g. "Hyva/default"), resolved for EVERY store view.
     *
     * DesignInterface::getDesignTheme() returns an unpopulated theme under CLI — nothing has
     * loaded a frontend design for a request that does not exist — so reading it there yields an
     * empty name. Going through the stored design configuration instead gives the same answer the
     * storefront would, and doing it per store view matters because the theme is a store-scoped
     * setting: one view on Hyvä and another on Luma is a supported (and easily missed) setup.
     *
     * @return array<string, string> store code => theme path
     */
    private function resolveFrontendThemes(): array
    {
        $themes = [];

        foreach ($this->storeManager->getStores() as $store) {
            try {
                $identifier = $this->design->getConfigurationDesignTheme(
                    \Magento\Framework\App\Area::AREA_FRONTEND,
                    ['store' => $store->getId()]
                );
                if (!$identifier) {
                    continue;
                }
                $theme = is_numeric($identifier)
                    ? $this->themeProvider->getThemeById((int) $identifier)
                    : $this->themeProvider->getThemeByFullPath('frontend/' . $identifier);

                $path = $theme ? (string) $theme->getThemePath() : (string) $identifier;
                if ($path !== '') {
                    $themes[(string) $store->getCode()] = $path;
                }
            } catch (\Throwable $e) {
                // a single unresolvable store view must not sink the whole report
            }
        }

        return $themes;
    }

    /**
     * @return Check[]
     */
    public function run(): array
    {
        $checks = [];
        $client = $this->resolveClient();

        $checks = array_merge($checks, $this->checkCluster($client));
        $checks = array_merge($checks, $this->checkIndices($client));
        $checks = array_merge($checks, $this->checkIndexers());
        $checks = array_merge($checks, $this->checkCron());
        $checks = array_merge($checks, $this->checkFacets($client));
        $checks = array_merge($checks, $this->checkPlp());
        $checks = array_merge($checks, $this->checkTheme());
        $checks = array_merge($checks, $this->checkCheckout());
        $checks = array_merge($checks, $this->checkCommerce());
        $checks = array_merge($checks, $this->checkLocking());
        foreach ($this->checkProviders as $provider) {
            $checks = array_merge($checks, $provider->check());
        }
        $checks = array_merge($checks, $this->checkPhp());

        return $checks;
    }

    /**
     * Public so check providers from other modules can reuse the resolved client.
     */
    public function resolveClient()
    {
        try {
            return $this->clientResolver
                ->create($this->engineResolver->getCurrentSearchEngine())
                ->getOpenSearchClient();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return Check[]
     */
    private function checkCluster($client): array
    {
        $out = [];
        $engine = (string) $this->scopeConfig->getValue('catalog/search/engine');
        $host = (string) $this->scopeConfig->getValue('catalog/search/opensearch_server_hostname');
        $port = (string) $this->scopeConfig->getValue('catalog/search/opensearch_server_port');

        if (stripos($engine, 'opensearch') === false && stripos($engine, 'elastic') === false) {
            $out[] = Check::fail(
                self::G_CLUSTER,
                'Search engine',
                sprintf('catalog/search/engine is "%s"', $engine !== '' ? $engine : '(unset)'),
                'FastMagento serves from OpenSearch/Elasticsearch. Set Stores > Configuration > '
                . 'Catalog > Catalog > Catalog Search > Search Engine.'
            );
            return $out;
        }

        if (!$client) {
            $out[] = Check::fail(
                self::G_CLUSTER,
                'Connection',
                sprintf('Could not build a search client for engine "%s" (%s:%s)', $engine, $host, $port),
                'Check the host/port under Catalog Search and that the cluster is reachable from '
                . 'this server: curl -s ' . ($host ?: 'localhost') . ':' . ($port ?: '9200')
            );
            return $out;
        }

        try {
            $health = $client->cluster()->health();
            $status = (string) ($health['status'] ?? 'unknown');
            $detail = sprintf(
                'engine=%s host=%s:%s status=%s nodes=%s',
                $engine,
                $host,
                $port,
                $status,
                (string) ($health['number_of_nodes'] ?? '?')
            );
            // A single-node cluster is permanently yellow (replicas unassigned). That is normal
            // and must not be reported as a problem, or every dev box fails its own health check.
            $out[] = $status === 'red'
                ? Check::fail(self::G_CLUSTER, 'Cluster health', $detail, 'Cluster is RED — shards are unavailable. Fix the cluster before reindexing.')
                : Check::ok(self::G_CLUSTER, 'Cluster health', $detail . ($status === 'yellow' ? ' (yellow is normal on a single node)' : ''));
        } catch (\Throwable $e) {
            $out[] = Check::fail(self::G_CLUSTER, 'Cluster health', $e->getMessage(), 'Verify the cluster is up and reachable.');
        }

        // Index-prefix collision. Two installs sharing one cluster on the default "magento2"
        // prefix silently overwrite each other's indices — observed on a real dev machine.
        $prefix = (string) $this->scopeConfig->getValue('catalog/search/opensearch_index_prefix');
        if ($prefix === '' || $prefix === 'magento2') {
            $out[] = Check::warn(
                self::G_CLUSTER,
                'Index prefix',
                sprintf('Using the default prefix "%s"', $prefix !== '' ? $prefix : 'magento2'),
                'If any other Magento install shares this cluster, both write the same index names '
                . 'and destroy each other on reindex. Set a unique prefix per install under '
                . 'Catalog Search > OpenSearch Index Prefix, then reindex.'
            );
        } else {
            $out[] = Check::ok(self::G_CLUSTER, 'Index prefix', sprintf('"%s"', $prefix));
        }

        return $out;
    }

    /**
     * @return Check[]
     */
    private function checkIndices($client): array
    {
        $out = [];
        if (!$client) {
            return [Check::skip(self::G_INDEX, 'Indices', 'No search client — see Cluster above.')];
        }

        $productCount = (int) $this->resource->getConnection()->fetchOne(
            $this->resource->getConnection()->select()
                ->from($this->resource->getTableName('catalog_product_entity'), 'COUNT(*)')
        );

        $approvedReviews = (int) $this->resource->getConnection()->fetchOne(
            $this->resource->getConnection()->select()
                ->from($this->resource->getTableName('review'), 'COUNT(*)')
                ->where('status_id = ?', \Magento\Review\Model\Review::STATUS_APPROVED)
        );

        $indices = [
            'product serving index' => [$this->openSearchConfig->getIndexName(), $productCount],
            'category serving index' => [$this->openSearchConfig->getCategoryIndexName(), null],
            'attribute option dictionary' => [$this->openSearchConfig->getAttributeOptionIndexName(), null],
            'review index' => [$this->openSearchConfig->getReviewIndexName(), $approvedReviews],
        ];

        try {
            $mapping = $client->indices()->getMapping(['index' => $this->openSearchConfig->getIndexName()]);
            $props = (array) (reset($mapping)['mappings']['properties'] ?? []);
            $out[] = ($props['request_path']['type'] ?? null) === 'keyword'
                ? Check::ok(self::G_INDEX, 'Product URL routing', 'request_path is a keyword field — product URLs resolve from the index')
                : Check::warn(
                    self::G_INDEX,
                    'Product URL routing',
                    'request_path is not mapped as a keyword on ' . $this->openSearchConfig->getIndexName() . ' — product URLs still resolve in MySQL',
                    'Run: bin/magento indexer:reindex fastmagento_product (rebuilds the index with the current mapping)'
                );
        } catch (\Throwable $e) {
            // Mapping unreadable: the count checks below will say what is wrong with the index.
        }

        foreach ($indices as $label => [$indexName, $expected]) {
            if (!$indexName) {
                continue;
            }
            try {
                $stats = $client->count(['index' => $indexName]);
                $docs = (int) ($stats['count'] ?? 0);

                if ($docs === 0 && $expected === 0) {
                    $out[] = Check::ok(self::G_INDEX, $label, sprintf('%s is empty, and so is the source table', $indexName));
                    continue;
                }
                if ($docs === 0) {
                    $out[] = Check::fail(
                        self::G_INDEX,
                        $label,
                        sprintf('%s is EMPTY', $indexName),
                        'Run: bin/magento indexer:reindex ' . implode(' ', InitializeIndexers::INDEXERS)
                    );
                    continue;
                }

                // A serving index far below the catalogue size is the signature of the silent
                // partial-index bug: bulk items rejected per-item while the reindex "succeeded".
                if ($expected !== null && $expected > 0 && $docs < ($expected * 0.9)) {
                    $out[] = Check::warn(
                        self::G_INDEX,
                        $label,
                        sprintf('%s holds %d docs but the catalogue has %d products', $indexName, $docs, $expected),
                        'Documents were likely rejected during bulk indexing. Reindex and read the '
                        . 'error; if it mentions "Limit of total fields", raise FastMagento > '
                        . 'Indexing > OpenSearch Field Limit.'
                    );
                    continue;
                }

                $out[] = Check::ok(self::G_INDEX, $label, sprintf('%s (%d docs)', $indexName, $docs));
            } catch (\Throwable $e) {
                $out[] = Check::fail(
                    self::G_INDEX,
                    $label,
                    sprintf('%s is missing or unreadable: %s', $indexName, $e->getMessage()),
                    'Run: bin/magento indexer:reindex ' . implode(' ', InitializeIndexers::INDEXERS)
                );
            }
        }

        // Mapping headroom — the check that would have caught the silent truncation up front.
        try {
            $indexName = $this->openSearchConfig->getIndexName();
            $configured = $this->indexSettings->getTotalFieldsLimit();
            $settings = $client->indices()->getSettings(['index' => $indexName]);
            $node = reset($settings);
            $effective = (int) ($node['settings']['index']['mapping']['total_fields']['limit'] ?? 1000);
            $mapping = $client->indices()->getMapping(['index' => $indexName]);
            $mapNode = reset($mapping);
            $used = $this->countFields($mapNode['mappings'] ?? []);

            $detail = sprintf('%d of %d fields mapped (module setting: %d)', $used, $effective, $configured);
            if ($effective > 0 && $used >= $effective * 0.9) {
                $out[] = Check::warn(
                    self::G_INDEX,
                    'Mapping headroom',
                    $detail,
                    'Close to the ceiling — raise FastMagento > Indexing > OpenSearch Field Limit '
                    . 'and reindex before it starts rejecting documents.'
                );
            } elseif ($effective < $configured) {
                $out[] = Check::warn(
                    self::G_INDEX,
                    'Mapping headroom',
                    $detail,
                    'The live index was created before the field limit was configured. '
                    . 'Reindex to recreate it with the configured limit.'
                );
            } else {
                $out[] = Check::ok(self::G_INDEX, 'Mapping headroom', $detail);
            }
        } catch (\Throwable $e) {
            $out[] = Check::skip(self::G_INDEX, 'Mapping headroom', $e->getMessage());
        }

        return $out;
    }

    /**
     * Count leaf fields in a mapping, which is what total_fields.limit actually counts.
     *
     * @param array<string, mixed> $mapping
     */
    private function countFields(array $mapping): int
    {
        $count = 0;
        $properties = $mapping['properties'] ?? [];
        foreach ($properties as $definition) {
            $count++;
            if (is_array($definition)) {
                if (isset($definition['properties'])) {
                    $count += $this->countFields($definition);
                }
                if (isset($definition['fields']) && is_array($definition['fields'])) {
                    $count += count($definition['fields']);
                }
            }
        }
        return $count;
    }

    /**
     * @return Check[]
     */
    private function checkIndexers(): array
    {
        $out = [];
        foreach (InitializeIndexers::INDEXERS as $indexerId) {
            try {
                $indexer = $this->indexerFactory->create()->load($indexerId);
                $valid = !$indexer->isInvalid();
                $scheduled = $indexer->isScheduled();
                $detail = sprintf(
                    'status=%s mode=%s',
                    $indexer->getStatus(),
                    $scheduled ? 'Update by Schedule' : 'Update on Save'
                );

                if (!$valid) {
                    $out[] = Check::fail(
                        self::G_INDEXER,
                        $indexerId,
                        $detail,
                        'Run: bin/magento indexer:reindex ' . $indexerId
                    );
                } elseif (!$scheduled) {
                    $out[] = Check::warn(
                        self::G_INDEXER,
                        $indexerId,
                        $detail,
                        'This module ships etc/mview.xml for schedule mode; on Update on Save every '
                        . 'product save reprojects synchronously. Run: bin/magento indexer:set-mode '
                        . 'schedule ' . $indexerId
                    );
                } else {
                    $out[] = Check::ok(self::G_INDEXER, $indexerId, $detail);
                }
            } catch (\Throwable $e) {
                $out[] = Check::fail(
                    self::G_INDEXER,
                    $indexerId,
                    'Not registered: ' . $e->getMessage(),
                    'Run: bin/magento setup:upgrade'
                );
            }
        }
        return $out;
    }

    /**
     * @return Check[]
     */
    private function checkCron(): array
    {
        // Schedule mode is worthless without a running cron: the mview backlog never drains and
        // the index goes stale while the storefront keeps serving old data.
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName('cron_schedule'), ['executed_at'])
                ->where('job_code = ?', 'indexer_update_all_views')
                ->where('status = ?', 'success')
                ->order('executed_at DESC')
                ->limit(1);
            $last = $connection->fetchOne($select);

            if (!$last) {
                return [Check::fail(
                    self::G_CRON,
                    'Indexer cron',
                    'No successful indexer_update_all_views run on record',
                    'Scheduled indexers will never update. Install Magento cron: '
                    . '* * * * * php bin/magento cron:run'
                )];
            }

            $ageMinutes = (int) round((time() - strtotime((string) $last)) / 60);
            $detail = sprintf('last success %s (%d min ago)', $last, $ageMinutes);

            return [$ageMinutes > 30
                ? Check::warn(self::G_CRON, 'Indexer cron', $detail, 'Cron looks stalled — scheduled reindexes are not running.')
                : Check::ok(self::G_CRON, 'Indexer cron', $detail)];
        } catch (\Throwable $e) {
            return [Check::skip(self::G_CRON, 'Indexer cron', $e->getMessage())];
        }
    }

    /**
     * @return Check[]
     */
    private function checkFacets($client): array
    {
        $out = [];
        $configured = trim((string) $this->scopeConfig->getValue('fastmagento/search/facet_attributes'));
        $codes = $configured === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $configured))));

        if ($codes === []) {
            $auto = $this->facetAttributes->getAutoDetectedCodes();
            $out[] = $auto
                ? Check::ok(self::G_FACETS, 'Facet attributes', 'auto-detected: ' . implode(', ', $auto))
                : Check::warn(
                    self::G_FACETS,
                    'Facet attributes',
                    'None configured and none auto-detected',
                    'No product attribute is flagged "Use in Search Results Layered Navigation". '
                    . 'Run: bin/magento fastmagento:doctor --fix (or bin/magento setup:upgrade) to '
                    . 'auto-enable it for every attribute already filterable in category layered '
                    . 'navigation — or set the flag manually (Stores > Attributes > Product > '
                    . '[attribute] > Storefront Properties). Then reindex: '
                    . 'bin/magento indexer:reindex catalogsearch_fulltext'
                );
        } else {
            $problems = $this->facetAttributes->findUnusable($codes);
            if ($problems) {
                foreach ($problems as $code => $reason) {
                    $out[] = Check::fail(
                        self::G_FACETS,
                        sprintf('Facet "%s"', $code),
                        $reason,
                        'This facet will render nothing until fixed. Clear the setting entirely to '
                        . 'auto-detect usable attributes instead.'
                    );
                }
            } else {
                $out[] = Check::ok(self::G_FACETS, 'Facet attributes', 'configured: ' . implode(', ', $codes));
            }
        }

        // The dictionary is what makes labels resolvable on a configurable catalogue. Without it
        // every attribute facet is dropped for want of a label — silently, before this check.
        try {
            $map = $this->optionDictionary->getAttributeIdsByCode();
            $out[] = $map
                ? Check::ok(self::G_FACETS, 'Option dictionary', sprintf('%d attribute(s) with resolvable labels', count($map)))
                : Check::fail(
                    self::G_FACETS,
                    'Option dictionary',
                    'Empty or unreadable',
                    'Attribute facets cannot be labelled and will be dropped. Run: '
                    . 'bin/magento indexer:reindex fastmagento_attribute_option'
                );
        } catch (\Throwable $e) {
            $out[] = Check::fail(self::G_FACETS, 'Option dictionary', $e->getMessage(), 'Run: bin/magento indexer:reindex fastmagento_attribute_option');
        }

        return $out;
    }

    /**
     * @return Check[]
     */
    private function checkPlp(): array
    {
        $on = $this->scopeConfig->isSetFlag(\ParkkTech\FastMagento\Model\Plp\ListingHydrator::XML_PATH_ENABLED);
        if (!$on) {
            return [Check::warn(
                self::G_PLP,
                'Category listing source',
                'native EAV — OpenSearch listing serving is off',
                'Set FastMagento > Product Listing (PLP) > Serve Listing From OpenSearch to Yes, '
                . 'or run: bin/magento config:set fastmagento/plp/serve_listing 1'
            )];
        }

        $out = [Check::ok(self::G_PLP, 'Category listing source', 'OpenSearch (falls back to EAV per page on any index miss)')];

        // The listing swap only takes effect if the search engine's collection virtual types were
        // successfully re-pointed; a third-party module redefining them would silently win.
        $expected = \Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection::class;
        try {
            // Must read the FRONTEND area config: the override is frontend-scoped on purpose, so
            // the global/CLI config legitimately still shows the core class and reading it here
            // would report a failure on a perfectly healthy install.
            $frontendConfig = $this->configLoader->load(\Magento\Framework\App\Area::AREA_FRONTEND);
            $actual = $frontendConfig['virtualTypes']['elasticsearchCategoryCollection'] ?? null;
            if ($actual !== null && !is_a((string)$actual, $expected, true)) {
                $out[] = Check::fail(
                    self::G_PLP,
                    'Listing collection class',
                    sprintf('elasticsearchCategoryCollection resolves to %s', $actual),
                    'The resolved collection does not inherit the supported fulltext hydration hook. '
                    . 'Review its load implementation and the FastMagento collection plugin.'
                );
            } else {
                $out[] = Check::ok(self::G_PLP, 'Listing collection class', 'Fulltext collection supports FastMagento pre-SQL hydration; third-party class retained');
            }
        } catch (\Throwable $e) {
            $out[] = Check::skip(self::G_PLP, 'Listing collection class', $e->getMessage());
        }

        $out[] = $this->checkUserDefinedAttributeCache();

        // A fallback is silent by design (the page still renders — through the EAV query storm),
        // so the hydrator records every occurrence and this is where it becomes visible. Measured:
        // ~27 SELECTs hydrated vs ~286 fallen back on the same 12-product page.
        $fallbacks = $this->plpFallbackRecorder->read();
        if ($fallbacks !== null) {
            $ageHours = (time() - $fallbacks['last_at']) / 3600;
            $detail = sprintf(
                '%d recorded (last %s ago): %s',
                $fallbacks['count'],
                $ageHours < 1 ? 'under an hour' : sprintf('%.0f hour(s)', $ageHours),
                $fallbacks['last_reason']
            );
            $fix = 'Listings silently rendered through the native EAV path (hundreds of MySQL '
                . 'queries per page) instead of OpenSearch. Fix the recorded reason — typically: '
                . 'bin/magento indexer:reindex fastmagento_product, and make sure Magento cron '
                . 'runs so the index stops drifting. Then browse a category page and run '
                . 'fastmagento:doctor --fix to clear this record.';
            $out[] = $ageHours <= 48
                ? Check::fail(self::G_PLP, 'Listing fallbacks', $detail, $fix)
                : Check::warn(self::G_PLP, 'Listing fallbacks', $detail . ' — none in the last 48h', $fix);
        } else {
            $out[] = Check::ok(self::G_PLP, 'Listing fallbacks', 'none recorded — every listing served from OpenSearch');
        }

        return $out;
    }

    /**
     * Magento re-reads every USER-DEFINED attribute from the database on each request unless
     * dev/caching/cache_user_defined_attributes is on — it ships off.
     *
     * This is the single biggest remaining cost on a listing once products come from OpenSearch,
     * and it is not product data, which is why it survives the OpenSearch work. The search request
     * declares one aggregation per filterable attribute, and Elasticsearch\..\Query\Builder     * Aggregation::buildBucket() resolves each bucket's field name through
     * Eav\Model\Config::getAttribute(). With the flag off, every one of those misses the EAV cache
     * and costs two queries — eav_attribute by code, then catalog_eav_attribute by id — on a warm
     * cache, every request.
     *
     * Measured on a 21-filterable-attribute catalogue: 41 of 81 warm listing queries, gone when
     * the flag is on. The trade-off is Magento's own: cached attribute metadata goes stale until
     * the EAV cache is flushed, so an admin editing an attribute needs a cache flush to see it.
     */
    private function checkUserDefinedAttributeCache(): Check
    {
        if ($this->scopeConfig->isSetFlag('dev/caching/cache_user_defined_attributes')) {
            return Check::ok(
                self::G_PLP,
                'User-defined attribute cache',
                'on — attribute metadata is served from the EAV cache'
            );
        }

        return Check::warn(
            self::G_PLP,
            'User-defined attribute cache',
            'off — every filterable attribute is re-read from the database on each request',
            'Two queries per filterable attribute per page render, warm cache included. '
            . 'Run: bin/magento config:set dev/caching/cache_user_defined_attributes 1 '
            . '(then cache:flush). Attribute metadata edits need a cache flush to show up.'
        );
    }

    /**
     * @return Check[]
     */
    private function checkTheme(): array
    {
        $out = [];
        $takeover = $this->scopeConfig->isSetFlag('fastmagento/search/instant_search_enabled');
        $themes = $this->resolveFrontendThemes();

        if (!$themes) {
            $out[] = Check::skip(self::G_THEME, 'Active theme', 'Could not resolve a frontend theme for any store view.');
        } else {
            foreach ($themes as $storeCode => $path) {
                $flavour = match (true) {
                    stripos($path, 'hyva') !== false => ' [Hyvä]',
                    stripos($path, 'breeze') !== false => ' [Breeze]',
                    default => '',
                };
                // The storefront bundle has no jQuery/RequireJS/Alpine dependency, so there is no
                // longer an unsupported theme — but naming what was detected is what turns "it
                // doesn't work" into a five-second answer.
                $out[] = Check::ok(
                    self::G_THEME,
                    sprintf('Theme (store: %s)', $storeCode),
                    sprintf('%s%s — storefront JS is dependency-free', $path, $flavour)
                );
            }
        }

        $out[] = $takeover
            ? Check::ok(self::G_THEME, 'Instant search takeover', 'enabled — native search results are replaced by the OpenSearch grid')
            : Check::warn(
                self::G_THEME,
                'Instant search takeover',
                'disabled — native Magento search is in use',
                'Set FastMagento > Instant Search & Relevance > Enable Instant Search Results to Yes '
                . 'to serve the results page from OpenSearch.'
            );

        return $out;
    }

    /**
     * Adobe Commerce: the schema edition this install runs on, and the Commerce features whose
     * visibility rules an OpenSearch-served page does not apply.
     *
     * @return Check[]
     */
    private function checkCommerce(): array
    {
        $out = [];
        $out[] = Check::ok(
            self::G_COMMERCE,
            'Catalogue schema',
            $this->entityLink->isProductStaged()
                ? 'Adobe Commerce (content staging, link field row_id) — staged-table queries resolve through EntityLink'
                : 'Open Source (link field entity_id)'
        );

        if ($this->moduleManager->isEnabled('Magento_Staging')) {
            $out[] = Check::ok(
                self::G_COMMERCE,
                'Scheduled updates',
                'The index holds the currently active version of each product; a version switch is picked up '
                . 'by the reindex Commerce triggers when it applies. Admin preview of a future date shows '
                . 'current data on OpenSearch-served pages.'
            );
        }

        $permissions = $this->moduleManager->isEnabled('Magento_CatalogPermissions')
            && $this->scopeConfig->isSetFlag('catalog/magento_catalogpermissions/enabled');
        if ($permissions) {
            $out[] = Check::warn(
                self::G_COMMERCE,
                'Category permissions',
                'Enabled — OpenSearch-served listings and search do not apply per-customer-group category permissions',
                'A customer group denied a category can still see its products in FastMagento search and '
                . 'listings. Until permission-aware serving ships, keep the serving flags off on the store '
                . 'views that use category permissions (Stores > Configuration > FastMagento > Serving).'
            );
        }

        $sharedCatalog = $this->moduleManager->isEnabled('Magento_SharedCatalog')
            && $this->scopeConfig->isSetFlag('btob/website_configuration/sharedcatalog_active');
        if ($sharedCatalog) {
            $out[] = Check::warn(
                self::G_COMMERCE,
                'B2B shared catalogs',
                'Active — OpenSearch-served listings and search do not apply shared-catalog product visibility',
                'Shared catalogs hide products per company through category permissions; the index has no '
                . 'notion of them. Keep the serving flags off on B2B store views until shared-catalog-aware '
                . 'serving ships.'
            );
        }

        return $out;
    }

    /**
     * @return Check[]
     */
    private function checkCheckout(): array
    {
        if (!$this->moduleManager->isEnabled('ParkkTech_FastMagentoCheckout')) {
            return [Check::skip(self::G_CHECKOUT, 'FastMagento Checkout', 'not installed')];
        }

        $out = [];
        // Ships disabled on purpose — but "installed and nothing happened" is the #1 confusion.
        $enabled = $this->scopeConfig->isSetFlag('fastmagentocheckout/general/enabled');
        $out[] = $enabled
            ? Check::ok(self::G_CHECKOUT, 'Fast checkout', 'enabled')
            : Check::warn(
                self::G_CHECKOUT,
                'Fast checkout',
                'Installed but NOT enabled (this is the shipped default)',
                'The module never activates itself. After QA, set FastMagento Checkout > General > '
                . 'Enabled to Yes, or run: bin/magento config:set fastmagentocheckout/general/enabled 1'
            );

        // It renders through the Luma/Knockout checkout.root block, which Hyvä's default theme
        // does not have. Without the Luma-checkout fallback the module is simply inert — the
        // checkout still works, it is just the stock one, which reads as "the module did nothing".
        $hyvaStores = [];
        foreach ($this->resolveFrontendThemes() as $storeCode => $path) {
            if (stripos($path, 'hyva') !== false) {
                $hyvaStores[] = $storeCode;
            }
        }

        if ($hyvaStores && !$this->moduleManager->isEnabled('Hyva_LumaCheckout')) {
            $out[] = Check::fail(
                self::G_CHECKOUT,
                'Hyvä checkout compatibility',
                sprintf('Hyvä is active on store view(s) %s but Hyva_LumaCheckout is not enabled', implode(', ', $hyvaStores)),
                'FastMagento Checkout extends the Luma checkout.root block, which Hyvä does not '
                . 'render, so it stays inert. Install the free fallback: '
                . 'composer require hyva-themes/magento2-luma-checkout'
            );
        } elseif ($hyvaStores) {
            $out[] = Check::ok(self::G_CHECKOUT, 'Hyvä checkout compatibility', 'Hyva_LumaCheckout enabled');
        }

        $out = array_merge($out, $this->checkFallbackThemeDeployed());

        return $out;
    }

    /**
     * Hyva_LumaCheckout brings Hyva_ThemeFallback, which SWAPS THE DESIGN THEME AT RUNTIME for the
     * configured URL segments — /checkout/index among them. So checkout renders in a different
     * theme (Magento/luma by default) from the rest of the storefront, and that theme needs its own
     * deployed static content.
     *
     * Nothing tells you when it is missing. The checkout returns HTTP 200 and every Luma
     * CSS/JS/font/logo 404s underneath: no styling at all, and because RequireJS itself is one of
     * the 404s the Knockout checkout never boots either. It reads as a broken module.
     *
     * It is easy to arrive at by accident. A full `-a frontend` deploy that dies on an unrelated
     * broken theme can abort before reaching the fallback theme, and the natural workaround —
     * pinning the deploy to the storefront theme with `--theme` — skips it too, every time,
     * forever. Prefer `--exclude-theme <broken>` so additional themes keep getting deployed.
     *
     * @return Check[]
     */
    private function checkFallbackThemeDeployed(): array
    {
        if (!$this->scopeConfig->isSetFlag('hyva_theme_fallback/general/enable')) {
            return [];
        }

        $themeFullPath = trim((string) $this->scopeConfig->getValue('hyva_theme_fallback/general/theme_full_path'), '/');
        if ($themeFullPath === '') {
            return [Check::skip(
                self::G_CHECKOUT,
                'Checkout theme static content',
                'Theme fallback is on but no theme_full_path is configured'
            )];
        }

        try {
            $static = $this->filesystem->getDirectoryRead(\Magento\Framework\App\Filesystem\DirectoryList::STATIC_VIEW);
        } catch (\Throwable $e) {
            return [Check::skip(self::G_CHECKOUT, 'Checkout theme static content', $e->getMessage())];
        }

        $out = [];
        foreach ($this->frontendLocales() as $storeCode => $locale) {
            $base = $themeFullPath . '/' . $locale;

            // A theme that was never deployed still gets a directory: Magento writes
            // requirejs-config.js there at runtime. Deployed CSS is the honest signal.
            $css = [];
            try {
                $css = $static->search($base . '/css/*.css');
            } catch (\Throwable $e) {
                $css = [];
            }

            if ($css) {
                $out[] = Check::ok(
                    self::G_CHECKOUT,
                    sprintf('Checkout theme static content (store: %s)', $storeCode),
                    sprintf('%s deployed for %s', $themeFullPath, $locale)
                );
                continue;
            }

            $out[] = Check::fail(
                self::G_CHECKOUT,
                sprintf('Checkout theme static content (store: %s)', $storeCode),
                sprintf('%s has no deployed CSS for %s — checkout will render unstyled', $themeFullPath, $locale),
                'Theme fallback renders checkout in this theme, so its static content must be '
                . 'deployed alongside the storefront theme. Run: bin/magento '
                . 'setup:static-content:deploy -f -a frontend ' . $locale
                . ' (add --exclude-theme <broken-theme> rather than pinning --theme, which skips this one).'
            );
        }

        return $out;
    }

    /**
     * Whether the DEPLOYED storefront bundle contains a given symbol.
     *
     * The source having it proves nothing: `setup:static-content:deploy -f` leaves an existing file
     * untouched and still exits 0, so source and vendor can both be current while the browser is
     * served a build from weeks ago. Null means the file could not be found or read, which is a
     * different answer from "found and stale".
     */
    /**
     * @param string $bundle module-relative static path, e.g. "ParkkTech_FastMagento/js/fastmagento.js"
     */
    public function deployedBundleCarries(string $needle, string $bundle = 'ParkkTech_FastMagento/js/fastmagento.js'): ?bool
    {
        // Only themes a store view actually RENDERS. Checking every deployed copy sounds safer and
        // is not: this store keeps a permanently stale Swissup/breeze-blank build, because that
        // theme is excluded from every deploy on purpose (its LESS crashes the deployer), and its
        // modules are disabled so nothing renders it. Failing on that would be a doctor that is red
        // for a condition no shopper can experience, which trains people to ignore it.
        $themes = $this->resolveFrontendThemes();
        if (!$themes) {
            return null;
        }

        try {
            $static = $this->filesystem->getDirectoryRead(
                \Magento\Framework\App\Filesystem\DirectoryList::STATIC_VIEW
            );
        } catch (\Throwable $e) {
            return null;
        }

        $seen = false;
        foreach (array_unique($themes) as $themePath) {
            try {
                $matches = $static->search(
                    'frontend/' . $themePath . '/*/' . $bundle
                );
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($matches as $path) {
                try {
                    $contents = $static->readFile($path);
                } catch (\Throwable $e) {
                    continue;
                }
                $seen = true;
                if (strpos($contents, $needle) === false) {
                    // One stale locale on one live theme is a whole audience reporting nothing.
                    return false;
                }
            }
        }

        return $seen ? true : null;
    }

    /**
     * @return array<string, string> store code => locale
     */
    private function frontendLocales(): array
    {
        $locales = [];
        try {
            foreach ($this->storeManager->getStores() as $store) {
                if (!$store->getIsActive()) {
                    continue;
                }
                $locales[$store->getCode()] = (string) $this->scopeConfig->getValue(
                    'general/locale/code',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $store->getId()
                ) ?: 'en_US';
            }
        } catch (\Throwable $e) {
            $locales = [];
        }

        return $locales;
    }

    /**
     * @return Check[]
     */
    /**
     * Magento's cache-lock provider.
     *
     * WHY THIS IS A FASTMAGENTO CONCERN
     * ---------------------------------
     * The default provider is `db`, which implements every lock as a MySQL round-trip pair —
     * SELECT GET_LOCK(...) then SELECT RELEASE_LOCK(...). Magento takes one lock per cache entry
     * it populates, so the cost lands entirely on cache-cold page loads: measured on a stock
     * 2.4.9 + Luma sample-data store, a cold category page spent 56 of its 179 queries (31%) on
     * lock traffic and a cold product page 14 of 78 (18%).
     *
     * That matters here because FastMagento's whole purpose is removing catalogue queries from
     * those same page loads. A store that has moved its listing to OpenSearch and still runs the
     * db lock provider is paying more in lock round-trips than in product reads — the remaining
     * DB time is not the catalogue any more, and a profiler session that does not know this reads
     * as "FastMagento didn't help".
     *
     * Locks are not taken when the cache is warm, so this never shows up in steady-state numbers.
     * It is purely a cold-start and cache-flush cost, which is exactly when a store is measuring.
     */
    private function checkLocking(): array
    {
        $out = [];

        try {
            $provider = (string) ($this->deploymentConfig->get('lock/provider') ?: 'db');
        } catch (\Throwable $e) {
            return [Check::skip(self::G_LOCK, 'Lock provider', 'could not read app/etc/env.php: ' . $e->getMessage())];
        }

        if ($provider === 'db') {
            $out[] = Check::warn(
                self::G_LOCK,
                'Cache lock provider',
                'db — every cache lock costs a GET_LOCK + RELEASE_LOCK round-trip (~31% of the '
                . 'queries on a cache-cold category page)',
                'Switch to the file provider, which takes no SQL at all: '
                . 'bin/magento setup:config:set --lock-provider=file '
                . '--lock-file-path=<magento-root>/var/locks (then bin/magento cache:flush). '
                . 'Use redis/zookeeper instead if this store runs more than one web node — '
                . 'file locks are local to one filesystem.'
            );

            return $out;
        }

        $detail = $provider;
        if ($provider === 'file') {
            $path = (string) ($this->deploymentConfig->get('lock/config/path') ?: '');
            $detail = $path !== '' ? "file ({$path})" : 'file';
            if ($path !== '' && !is_writable($path)) {
                $out[] = Check::fail(
                    self::G_LOCK,
                    'Cache lock provider',
                    "file, but {$path} is not writable — locking will fail",
                    'Create it and make it writable by the web user: '
                    . "mkdir -p {$path} && chmod 2775 {$path}"
                );

                return $out;
            }
        }

        $out[] = Check::ok(self::G_LOCK, 'Cache lock provider', $detail . ' — no SQL round-trips for locking');

        return $out;
    }

    private function checkPhp(): array
    {
        $out = [];
        $memory = ini_get('memory_limit');
        $bytes = $this->toBytes((string) $memory);
        $out[] = ($bytes > 0 && $bytes < 2 * 1024 * 1024 * 1024)
            ? Check::warn(self::G_PHP, 'memory_limit', (string) $memory, 'Magento recommends at least 2G for CLI/indexing.')
            : Check::ok(self::G_PHP, 'memory_limit', (string) $memory);

        $maxExecution = (int) ini_get('max_execution_time');
        $out[] = ($maxExecution > 0 && $maxExecution < 600)
            ? Check::warn(self::G_PHP, 'max_execution_time', (string) $maxExecution, 'Long reindex/deploy operations may be killed; 1800 or 0 is typical.')
            : Check::ok(self::G_PHP, 'max_execution_time', $maxExecution === 0 ? 'unlimited' : (string) $maxExecution);

        return $out;
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0; // unlimited
        }
        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
