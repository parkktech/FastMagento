<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Search;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

/**
 * Central read model for the FastMagento Search & Relevance settings (the OpenSearch
 * equivalent of Algolia's searchable attributes / custom ranking / typo tolerance). Keeps
 * all ranking knobs in one place so both the admin page and the query builder agree.
 */
class RelevanceConfig
{
    /** Built-in field boosts when no attributes are configured. */
    private const DEFAULT_WEIGHTS = [
        'name' => 5,
        'sku' => 6,
        'short_description' => 1,
        'description' => 1,
    ];

    /** The AI keyword layer field (see Setup\Patch\Data\AddSearchKeywordsAttribute). */
    public const KEYWORD_FIELD = 'fm_search_keywords';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $json,
        private readonly \ParkkTech\FastMagento\Model\Config\Source\FacetAttributes $facetAttributeSource
    ) {
    }

    /**
     * attribute code => search weight (higher ranks higher).
     *
     * @return array<string, float>
     */
    public function getSearchableAttributes(): array
    {
        $raw = (string) $this->value('searchable_attributes');
        if (trim($raw) === '') {
            return self::DEFAULT_WEIGHTS;
        }
        try {
            $rows = $this->json->unserialize($raw);
        } catch (\Throwable $e) {
            return self::DEFAULT_WEIGHTS;
        }
        $weights = [];
        foreach ((array) $rows as $row) {
            $code = trim((string) ($row['attribute'] ?? ''));
            if ($code === '') {
                continue;
            }
            $weights[$code] = (float) ($row['weight'] ?? 1) ?: 1;
        }
        return $weights ?: self::DEFAULT_WEIGHTS;
    }

    /**
     * Weighted field list for a multi_match query, e.g. ['name^5','sku^6']. When the AI keyword
     * layer is enabled, `fm_search_keywords` is folded in at its configured weight (unless the
     * merchant already listed it explicitly), so keyword hits rank strongly without hand config.
     *
     * @return string[]
     */
    public function getBoostedFields(): array
    {
        $weights = $this->getSearchableAttributes();
        if ($this->isSearchKeywordsEnabled() && !isset($weights[self::KEYWORD_FIELD])) {
            $weights[self::KEYWORD_FIELD] = $this->getSearchKeywordsWeight();
        }

        $fields = [];
        foreach ($weights as $code => $weight) {
            $fields[] = $weight > 1 ? $code . '^' . rtrim(rtrim((string) $weight, '0'), '.') : $code;
        }
        return $fields;
    }

    /**
     * How multiple query terms combine: 'any' (OR, default), 'all' (AND), or 'most' (75%).
     */
    public function getSearchOperator(): string
    {
        $value = (string) $this->value('search_operator');
        return in_array($value, ['any', 'all', 'most'], true) ? $value : 'any';
    }

    /**
     * Boost applied to a contiguous-phrase match over scattered token hits. 0 disables.
     */
    public function getPhraseMatchBoost(): float
    {
        $raw = $this->value('phrase_match_boost');
        if ($raw === null || trim($raw) === '') {
            return 4.0;
        }
        return max(0.0, (float) $raw);
    }

    public function isSearchKeywordsEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('fastmagento/search/search_keywords_enabled', ScopeInterface::SCOPE_STORE);
    }

    public function getSearchKeywordsWeight(): float
    {
        $raw = $this->value('search_keywords_weight');
        $weight = $raw === null ? 0.0 : (float) $raw;
        return $weight > 0 ? $weight : 8.0;
    }

    public function isTypoToleranceEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('fastmagento/search/typo_tolerance', ScopeInterface::SCOPE_STORE);
    }

    public function isBoostInStockEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('fastmagento/search/boost_in_stock', ScopeInterface::SCOPE_STORE);
    }

    public function getCustomRankingAttribute(): string
    {
        return trim((string) $this->value('custom_ranking_attribute'));
    }

    public function getCustomRankingDirection(): string
    {
        return $this->value('custom_ranking_direction') === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Stop words to strip from queries (lower-cased).
     *
     * @return string[]
     */
    public function getStopwords(): array
    {
        $raw = mb_strtolower((string) $this->value('stopwords'));
        return array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split('/[,\s]+/', $raw) ?: []
        ))));
    }

    /**
     * Synonym equivalence groups (each a list of interchangeable terms, lower-cased).
     *
     * @return string[][]
     */
    public function getSynonymGroups(): array
    {
        $groups = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $this->value('synonyms')) ?: [] as $line) {
            $terms = array_values(array_unique(array_filter(array_map(
                'trim',
                explode(',', mb_strtolower($line))
            ))));
            if (count($terms) >= 2) {
                $groups[] = $terms;
            }
        }
        return $groups;
    }

    /**
     * @return string[]
     */
    public function getFacetAttributes(): array
    {
        $configured = (string) $this->value('facet_attributes');
        if (trim($configured) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $configured))));
        }

        // Blank used to mean "no attribute facets at all", so a store that never found this
        // setting simply had none — with nothing in the UI to say so. Blank now means AUTO:
        // derive the facets from the attributes Magento itself marks as usable in search-results
        // layered navigation, which is exactly the set the native index makes aggregatable.
        // An explicit value still wins, so anyone who configured this keeps their list.
        return $this->facetAttributeSource->getAutoDetectedCodes();
    }

    /**
     * Product columns per row on the instant-search results grid. Exposed so the search grid can
     * match the storefront's category grid instead of a CSS-hard-coded count. Clamped to 1..6;
     * blank/invalid falls back to 4.
     */
    public function getGridColumns(): int
    {
        $raw = $this->value('grid_columns');
        $cols = $raw === null ? 0 : (int) $raw;
        if ($cols < 1) {
            return 4;
        }
        return min(6, $cols);
    }

    private function value(string $field): ?string
    {
        return $this->scopeConfig->getValue('fastmagento/search/' . $field, ScopeInterface::SCOPE_STORE);
    }
}
