<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Search;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Helper\WriteLog;

/**
 * OpenSearch-backed instant-search / autocomplete query service.
 *
 * Relevance + facets come from Magento's native fulltext index (alias
 * `<prefix>_product_<storeId>`, which is properly analyzed and only holds search-visible
 * products); display fields (name, price, image, url) are hydrated from FastMagento's own
 * `magento2_products` _source in a single mget. This gives Algolia-style as-you-type
 * results without a bespoke mapping or reindex, and every call degrades to an empty result
 * set (never throws) so the storefront input can't break.
 */
class InstantSearch
{
    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly WriteLog $writeLog,
        private readonly RelevanceConfig $relevanceConfig
    ) {
    }

    /**
     * @param string $query
     * @param int $page 1-based
     * @param int $pageSize
     * @param array<string, string[]> $filters attributeCode => selected values (native field terms)
     * @param bool $withFacets aggregate category + price + configured attribute facets
     * @return array{query:string,total:int,page:int,page_size:int,products:array,facets:array}
     */
    public function search(
        string $query,
        int $page = 1,
        int $pageSize = 8,
        array $filters = [],
        bool $withFacets = false
    ): array {
        $query = trim($query);
        $page = max(1, $page);
        $pageSize = max(1, min(48, $pageSize));
        $empty = [
            'query' => $query,
            'total' => 0,
            'page' => $page,
            'page_size' => $pageSize,
            'products' => [],
            'facets' => [],
        ];
        if ($query === '') {
            return $empty;
        }

        try {
            $client = $this->getClient();
            $storeId = (int) $this->storeManager->getStore()->getId();

            $body = [
                'from' => ($page - 1) * $pageSize,
                'size' => $pageSize,
                'query' => $this->buildQuery($query, $filters),
                '_source' => ['name', 'sku'],
                'track_total_hits' => true,
            ];
            $sort = $this->buildSort();
            if ($sort) {
                $body['sort'] = $sort;
            }
            if ($withFacets) {
                $body['aggs'] = $this->buildAggregations();
            }

            $response = $client->getOpenSearchClient()->search([
                'index' => $this->getSearchIndex($storeId),
                'body' => $body,
            ]);

            $hits = $response['hits']['hits'] ?? [];
            $ids = array_map(static fn ($h) => (int) $h['_id'], $hits);

            return [
                'query' => $query,
                'total' => (int) ($response['hits']['total']['value'] ?? count($ids)),
                'page' => $page,
                'page_size' => $pageSize,
                'products' => $this->hydrateProducts($client, $ids),
                'facets' => $withFacets ? $this->formatFacets($response['aggregations'] ?? []) : [],
            ];
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] instant search failed: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * @param array<string, string[]> $filters
     * @return array<string, mixed>
     */
    private function buildQuery(string $query, array $filters): array
    {
        // bool_prefix gives good as-you-type behaviour; field boosts come from the admin
        // searchable-attributes weights (Algolia-style searchable attributes).
        $matchClause = [
            'query' => $query,
            'type' => 'bool_prefix',
            'fields' => $this->relevanceConfig->getBoostedFields(),
        ];
        if ($this->relevanceConfig->isTypoToleranceEnabled()) {
            $matchClause['fuzziness'] = 'AUTO';
        }
        $must = [['multi_match' => $matchClause]];

        $filterClauses = [];
        foreach ($filters as $field => $values) {
            $values = array_values(array_filter((array) $values, static fn ($v) => $v !== '' && $v !== null));
            if (!$values) {
                continue;
            }
            $filterClauses[] = ['terms' => [$this->filterField($field) => $values]];
        }

        $bool = ['must' => $must];
        if ($filterClauses) {
            $bool['filter'] = $filterClauses;
        }

        // Gently boost in-stock products above out-of-stock ones (keeps text relevance
        // primary, unlike a hard sort).
        if ($this->relevanceConfig->isBoostInStockEnabled()) {
            return [
                'function_score' => [
                    'query' => ['bool' => $bool],
                    'functions' => [[
                        'filter' => ['term' => ['is_out_of_stock' => 0]],
                        'weight' => 1.6,
                    ]],
                    'boost_mode' => 'multiply',
                    'score_mode' => 'sum',
                ],
            ];
        }
        return ['bool' => $bool];
    }

    /**
     * Custom-ranking tie-breaker: text relevance first, then the configured numeric
     * attribute (Algolia's custom ranking after textual relevance).
     *
     * @return array<int, mixed>
     */
    private function buildSort(): array
    {
        $attribute = $this->relevanceConfig->getCustomRankingAttribute();
        if ($attribute === '') {
            return [];
        }
        return [
            '_score' => 'desc',
            [$attribute => ['order' => $this->relevanceConfig->getCustomRankingDirection(), 'missing' => '_last', 'unmapped_type' => 'float']],
        ];
    }

    /**
     * Native fulltext field name for a facet/filter attribute. Category filter maps to
     * category_ids; everything else uses the attribute code as-is (Magento maps select /
     * filterable attributes under their code).
     */
    private function filterField(string $code): string
    {
        return $code === 'category' ? 'category_ids' : $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAggregations(): array
    {
        $aggs = [
            'category' => ['terms' => ['field' => 'category_ids', 'size' => 15]],
        ];
        foreach ($this->relevanceConfig->getFacetAttributes() as $code) {
            $aggs[$code] = ['terms' => ['field' => $code, 'size' => 15]];
        }
        return $aggs;
    }

    /**
     * Pull display docs from FastMagento's own index in one mget, preserving relevance order.
     *
     * @param mixed $client
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private function hydrateProducts($client, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $response = $client->getOpenSearchClient()->mget([
            'index' => $this->openSearchConfig->getIndexName(),
            'body' => ['ids' => array_map('strval', $ids)],
        ]);

        $byId = [];
        foreach ($response['docs'] ?? [] as $doc) {
            if (!empty($doc['found']) && isset($doc['_source'])) {
                $byId[(int) $doc['_id']] = $doc['_source'];
            }
        }

        $products = [];
        foreach ($ids as $id) {                 // keep relevance order from the search index
            if (isset($byId[$id])) {
                $products[] = $this->formatProduct($id, $byId[$id]);
            }
        }
        return $products;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function formatProduct(int $id, array $source): array
    {
        $price = (float) ($source['final_price'] ?? $source['price'] ?? 0);
        $regular = (float) ($source['price'] ?? $price);

        return [
            'id' => $id,
            'sku' => (string) ($source['sku'] ?? ''),
            'name' => (string) ($source['name'] ?? ''),
            'url' => $this->productUrl($source),
            'image' => $this->imageUrl((string) ($source['small_image'] ?? $source['thumbnail'] ?? $source['image'] ?? '')),
            'price' => $price,
            'regular_price' => $regular,
            'price_formatted' => $this->priceCurrency->format($price, false),
            'regular_price_formatted' => $regular > $price ? $this->priceCurrency->format($regular, false) : null,
            'in_stock' => (bool) ($source['is_in_stock'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function productUrl(array $source): string
    {
        $path = (string) ($source['url_path'] ?? $source['url_key'] ?? '');
        if ($path === '') {
            return $this->storeManager->getStore()->getBaseUrl() . 'catalog/product/view/id/' . ($source['entity_id'] ?? '');
        }
        $suffix = (string) $this->scopeConfig->getValue(
            'catalog/seo/product_url_suffix',
            ScopeInterface::SCOPE_STORE
        );
        return $this->storeManager->getStore()->getBaseUrl() . ltrim($path, '/') . $suffix;
    }

    private function imageUrl(string $path): string
    {
        if ($path === '' || $path === 'no_selection') {
            return '';
        }
        $media = $this->storeManager->getStore()
            ->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
        return rtrim($media, '/') . '/catalog/product' . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string, mixed> $aggregations
     * @return array<int, array<string, mixed>>
     */
    private function formatFacets(array $aggregations): array
    {
        $facets = [];
        foreach ($aggregations as $code => $agg) {
            $options = [];
            foreach ($agg['buckets'] ?? [] as $bucket) {
                $options[] = [
                    'value' => (string) $bucket['key'],
                    'count' => (int) $bucket['doc_count'],
                ];
            }
            if ($options) {
                $facets[] = ['attribute' => $code, 'options' => $options];
            }
        }
        return $facets;
    }

    private function getClient()
    {
        return $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
    }

    /**
     * Magento's native fulltext index alias for the store (analyzed, search-visible only).
     */
    private function getSearchIndex(int $storeId): string
    {
        $prefix = (string) ($this->scopeConfig->getValue('catalog/search/opensearch_index_prefix') ?: 'magento2');
        return $prefix . '_product_' . $storeId;
    }
}
