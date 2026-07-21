<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Controller\Search;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use ParkkTech\FastMagento\Model\OpenSearch\CategoryDataProvider;
use ParkkTech\FastMagento\Model\Search\InstantSearch;

/**
 * Instant-search results endpoint (/fastmagento/search/instant?q=&p=&filter[code][]=): the
 * paged product grid + facet payload that the results page re-renders in place as the user
 * types or toggles filters — no full page reload, Algolia-style.
 */
class Instant extends Action
{
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
        $request = $this->getRequest();
        $query = (string) $request->getParam('q', '');
        $page = (int) $request->getParam('p', 1);
        $pageSize = (int) $request->getParam('page_size', 12);

        $filters = [];
        foreach ((array) $request->getParam('filter', []) as $code => $values) {
            $filters[(string) $code] = is_array($values) ? $values : explode(',', (string) $values);
        }

        $result = $this->instantSearch->search($query, $page, $pageSize, $filters, true);
        $result['facets'] = $this->labelFacets($result['facets']);
        $result['pages'] = (int) ceil($result['total'] / max(1, $pageSize));

        return $this->jsonFactory->create()->setData($result);
    }

    /**
     * Add human labels to facet options: the category facet resolves ids to names from the
     * OpenSearch tree; other facets keep their raw values (option-id labels can be layered
     * in later from the attribute source).
     *
     * @param array<int, array<string, mixed>> $facets
     * @return array<int, array<string, mixed>>
     */
    private function labelFacets(array $facets): array
    {
        foreach ($facets as &$facet) {
            $isCategory = ($facet['attribute'] ?? null) === 'category';
            $facet['label'] = $isCategory ? 'Category' : ucwords(str_replace('_', ' ', (string) $facet['attribute']));
            foreach ($facet['options'] as &$option) {
                if ($isCategory) {
                    $doc = $this->categoryData->getById((int) $option['value']);
                    $option['label'] = $doc['name'] ?? $option['value'];
                    if (!$doc || (int) ($doc['level'] ?? 0) < 2) {
                        $option['skip'] = true;   // root/site categories
                    }
                } else {
                    $option['label'] = $option['value'];
                }
            }
            unset($option);
            $facet['options'] = array_values(array_filter(
                $facet['options'],
                static fn ($o) => empty($o['skip'])
            ));
        }
        unset($facet);

        return array_values(array_filter($facets, static fn ($f) => !empty($f['options'])));
    }
}
