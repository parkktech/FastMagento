<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Controller\Search;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use ParkkTech\FastMagento\Model\OpenSearch\CategoryDataProvider;
use ParkkTech\FastMagento\Model\Search\InstantSearch;

/**
 * Autocomplete endpoint (/fastmagento/search/suggest?q=...): a compact, as-you-type payload
 * for the header search dropdown — a handful of product hits (name/price/image/url), the
 * total match count, and the top matching categories resolved from the OpenSearch category
 * index.
 */
class Suggest extends Action
{
    private const PRODUCT_LIMIT = 6;
    private const CATEGORY_LIMIT = 5;

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly InstantSearch $instantSearch,
        private readonly CategoryDataProvider $categoryData
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $query = (string) $this->getRequest()->getParam('q', '');
        $result = $this->instantSearch->search($query, 1, self::PRODUCT_LIMIT, [], true);

        return $this->jsonFactory->create()->setData([
            'query' => $result['query'],
            'total' => $result['total'],
            'products' => $result['products'],
            'categories' => $this->topCategories($result['facets']),
        ]);
    }

    /**
     * Turn the category_ids facet into name+url suggestions using the OpenSearch tree.
     *
     * @param array<int, array<string, mixed>> $facets
     * @return array<int, array<string, mixed>>
     */
    private function topCategories(array $facets): array
    {
        $categoryFacet = null;
        foreach ($facets as $facet) {
            if (($facet['attribute'] ?? null) === 'category') {
                $categoryFacet = $facet;
                break;
            }
        }
        if (!$categoryFacet) {
            return [];
        }

        $out = [];
        foreach ($categoryFacet['options'] as $option) {
            $doc = $this->categoryData->getById((int) $option['value']);
            // Skip the site root / non-menu / unresolved categories.
            if (!$doc || (int) ($doc['level'] ?? 0) < 2 || empty($doc['name'])) {
                continue;
            }
            $requestPath = (string) ($doc['request_path'] ?? '');
            $out[] = [
                'id' => (int) $option['value'],
                'name' => (string) $doc['name'],
                'count' => (int) $option['count'],
                'url' => $requestPath !== '' ? $this->_url->getBaseUrl() . ltrim($requestPath, '/') : '',
            ];
            if (count($out) >= self::CATEGORY_LIMIT) {
                break;
            }
        }
        return $out;
    }
}
