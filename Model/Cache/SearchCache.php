<?php

namespace ParkkTech\FastMagento\Model\Cache;

use Magento\Framework\Cache\FrontendInterface;
use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Search\EngineResolverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use Psr\Log\LoggerInterface;

class SearchCache
{
    private $cache;
    private $clientResolver;
    private $engineResolver;
    private $openSearchConfig;
    private $logger;

    public function __construct(
        FrontendInterface $cache,
        ClientResolver $clientResolver,
        EngineResolverInterface $engineResolver,
        OpenSearchConfig $openSearchConfig,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->clientResolver = $clientResolver;
        $this->engineResolver = $engineResolver;
        $this->openSearchConfig = $openSearchConfig;
        $this->logger = $logger;
    }

    public function getCachedResults($query)
    {
        $cacheKey = 'fastmagento_search_' . md5($query);
        $cachedData = $this->cache->load($cacheKey);

        if ($cachedData) {
            // JSON, not native unserialize() — the cache payload is plain array data and JSON
            // decode cannot instantiate objects (no PHP object-injection surface).
            return json_decode($cachedData, true) ?: [];
        }

        try {
            $engine = $this->engineResolver->getCurrentSearchEngine();
            $searchClient = $this->clientResolver->create($engine);
            $indexName = $this->openSearchConfig->getIndexName();

            $response = $searchClient->getOpenSearchClient()->search([
                'index' => $indexName,
                'body' => [
                    'query' => ['match' => ['name' => $query]],
                    'size' => 10
                ]
            ]);

            $results = [];
            foreach ($response['hits']['hits'] as $hit) {
                $results[] = $hit['_source'];
            }

            $this->cache->save(json_encode($results), $cacheKey, ['fastmagento_search'], 3600);
            return $results;
        } catch (\Exception $e) {
            $this->logger->error('SearchCache error: ' . $e->getMessage());
            return [];
        }
    }
}
