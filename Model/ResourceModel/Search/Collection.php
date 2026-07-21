<?php
namespace ParkkTech\FastMagento\Model\ResourceModel\Search;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use ParkkTech\FastMagento\Helper\ShellProductBuilder;

class Collection extends ProductCollection
{
    private $connectionManager;
    private $scopeConfig;
    private $shellProductBuilder;

    const XML_PATH_INDEX_PREFIX = 'catalog/search/elasticsearch_index_prefix';

    public function __construct(
        \Magento\Framework\Data\Collection\EntityFactory $entityFactory,
        LoggerInterface $logger,
        \Magento\Framework\Data\Collection\Db\FetchStrategyInterface $fetchStrategy,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Eav\Model\Config $eavConfig,
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Eav\Model\EntityFactory $eavEntityFactory,
        \Magento\Catalog\Model\ResourceModel\Helper $resourceHelper,
        \Magento\Framework\Validator\UniversalFactory $universalFactory,
        StoreManagerInterface $storeManager,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Catalog\Model\Indexer\Product\Flat\State $catalogProductFlatState,
        ScopeConfigInterface $scopeConfig,
        ConnectionManager $connectionManager, // 🔹 OpenSearch Connection
        ShellProductBuilder $shellProductBuilder,
        ?\Magento\Framework\DB\Adapter\AdapterInterface $connection = null
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $eavConfig,
            $resource,
            $eavEntityFactory,
            $resourceHelper,
            $universalFactory,
            $storeManager,
            $moduleManager,
            $catalogProductFlatState,
            $scopeConfig,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null
        );

        $this->scopeConfig = $scopeConfig;
        $this->connectionManager = $connectionManager;
        $this->shellProductBuilder = $shellProductBuilder;
    }

    public function _loadEntities($printQuery = false, $logQuery = false)
    {
        $queryText = $this->getSearchQueryText();
        if (!$queryText) {
            return parent::_loadEntities($printQuery, $logQuery);
        }

        $indexPrefix = (string) $this->scopeConfig->getValue(self::XML_PATH_INDEX_PREFIX);
        $indexName = $indexPrefix . '_products';

        $pageSize = $this->getPageSize();
        $currentPage = $this->getCurPage();
        $offset = ($currentPage - 1) * $pageSize;

        // Get OpenSearch Client
        $client = $this->connectionManager->getConnection();
        if (!$client) {
            throw new \Exception('Failed to retrieve OpenSearch connection.');
        }

        // Build OpenSearch Query
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
                'from' => $offset
            ]
        ];

        // Execute OpenSearch Query
        $response = $client->search($query);
        if (!isset($response['hits']['hits'])) {
            return $this;
        }

        // Fetch product data directly from OpenSearch
        foreach ($response['hits']['hits'] as $hit) {
            $productData = $hit['_source'];

            // Build ShellNoEavProduct from OpenSearch doc instead of regular Product
            // This allows us to skip url_rewrite DB lookups
            $product = $this->shellProductBuilder->buildNoEavProductFromOsDoc($productData);

            $this->_items[$hit['_id']] = $product;
        }

        return $this;
    }

    private function getSearchQueryText()
    {
        return $this->_queryText ?? '';
    }
}
