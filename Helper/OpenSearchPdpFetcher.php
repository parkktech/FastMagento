<?php
namespace ParkkTech\FastMagento\Helper;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Search\EngineResolverInterface;
use Psr\Log\LoggerInterface;
use ParkkTech\FastMagento\Model\Indexer\ProductIndexer;

/**
 * A simple helper to do $searchClient->getOpenSearchClient()->get(...) by doc ID.
 */
class OpenSearchPdpFetcher
{
    private $clientResolver;
    private $engineResolver;
    private $logger;
    private $productIndexer;

    public function __construct(
        ClientResolver $clientResolver,
        EngineResolverInterface $engineResolver,
        LoggerInterface $logger,
        ProductIndexer $productIndexer
    ) {
        $this->clientResolver = $clientResolver;
        $this->engineResolver = $engineResolver;
        $this->logger = $logger;
        $this->productIndexer = $productIndexer;
    }

    /**
     * Batch fetch docs by id (single mget). Returns [entity_id => _source] for docs found.
     *
     * @param int[] $ids
     * @return array<int, array>
     */
    public function fetchByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        if (!$ids) {
            return $out;
        }
        try {
            $engine = $this->engineResolver->getCurrentSearchEngine();
            $searchClient = $this->clientResolver->create($engine);
            $indexName = $this->productIndexer->getIndexName();
            $resp = $searchClient->getOpenSearchClient()->mget([
                'index' => $indexName,
                'body' => ['ids' => array_map('strval', $ids)],
            ]);
            foreach ($resp['docs'] ?? [] as $doc) {
                if (!empty($doc['found']) && isset($doc['_source'])) {
                    $out[(int) $doc['_id']] = $doc['_source'];
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('OpenSearchPdpFetcher mget error: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Fetch doc by $id from the OS index. Return the _source or null if not found.
     */
    /**
     * @param int $id
     * @return array|null
     */
    public function fetchPdpById(int $id): ?array
    {
        try {
            // 1) get the engine code from admin config
            $engine = $this->engineResolver->getCurrentSearchEngine();
            // 2) build the client
            $searchClient = $this->clientResolver->create($engine);
            // 3) get the same index name from ProductIndexer
            $indexName = $this->productIndexer->getIndexName();

            $params = [
                'index' => $indexName,
                'id'    => (string)$id
            ];
            $resp = $searchClient->getOpenSearchClient()->get($params);
            if (isset($resp['_source'])) {
                return $resp['_source'];
            }
        } catch (\Exception $e) {
            $this->logger->error('OpenSearchPdpFetcher error: ' . $e->getMessage());
        }
        return null;
    }

}
