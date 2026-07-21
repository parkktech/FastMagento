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

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $json
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
     * Weighted field list for a multi_match query, e.g. ['name^5','sku^6'].
     *
     * @return string[]
     */
    public function getBoostedFields(): array
    {
        $fields = [];
        foreach ($this->getSearchableAttributes() as $code => $weight) {
            $fields[] = $weight > 1 ? $code . '^' . rtrim(rtrim((string) $weight, '0'), '.') : $code;
        }
        return $fields;
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
     * @return string[]
     */
    public function getFacetAttributes(): array
    {
        $configured = (string) $this->value('facet_attributes');
        if (trim($configured) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }

    private function value(string $field): ?string
    {
        return $this->scopeConfig->getValue('fastmagento/search/' . $field, ScopeInterface::SCOPE_STORE);
    }
}
