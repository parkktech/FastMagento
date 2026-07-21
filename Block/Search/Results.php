<?php

namespace ParkkTech\FastMagento\Block\Search;

use Magento\Framework\View\Element\Template;
use Magento\Framework\UrlInterface;
use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Search\EngineResolverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use Psr\Log\LoggerInterface;

class Results extends Template
{
    private $clientResolver;
    private $engineResolver;
    private $openSearchConfig;
    private $urlBuilder;
    private $logger;

    public function __construct(
        Template\Context $context,
        ClientResolver $clientResolver,
        EngineResolverInterface $engineResolver,
        OpenSearchConfig $openSearchConfig,
        UrlInterface $urlBuilder,
        LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->clientResolver = $clientResolver;
        $this->engineResolver = $engineResolver;
        $this->openSearchConfig = $openSearchConfig;
        $this->urlBuilder = $urlBuilder;
        $this->logger = $logger;
    }

    public function getProducts()
    {
        $queryText = $this->getSearchQuery();
        if (!$queryText) {
            return [];
        }

        $pageSize = (int) $this->getRequest()->getParam('limit', 12);
        $currentPage = (int) $this->getRequest()->getParam('p', 1);
        $offset = ($currentPage - 1) * $pageSize;

        try {
            // Get the engine code from admin config and build the client
            $engine = $this->engineResolver->getCurrentSearchEngine();
            $searchClient = $this->clientResolver->create($engine);
            $indexName = $this->openSearchConfig->getIndexName();

            $query = [
                'index' => $indexName,
                'body' => [
                    'query' => [
                        'bool' => [
                            'must' => [
                                ['match' => ['name' => $queryText]]
                            ]
                        ]
                    ],
                    'size' => $pageSize,
                    'from' => $offset,
                    'sort' => [['relevance' => 'desc']]
                ]
            ];

            $response = $searchClient->getOpenSearchClient()->search($query);
            return array_map(fn($hit) => $hit['_source'], $response['hits']['hits']);
        } catch (\Exception $e) {
            $this->logger->error('Search Results Block error: ' . $e->getMessage());
            return [];
        }
    }

    public function getSearchQuery()
    {
        return trim((string) $this->getRequest()->getParam('q', ''));
    }

    public function getProductUrl(array $product): string
    {
        return isset($product['url_key']) ? $this->urlBuilder->getUrl($product['url_key']) : '#';
    }

    public function getProductImage(array $product): string
    {
        return isset($product['image']) ? $product['image'] : '';
    }
}
