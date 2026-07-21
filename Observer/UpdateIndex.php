<?php

namespace ParkkTech\FastMagento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Search\EngineResolverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use Psr\Log\LoggerInterface;

class UpdateIndex implements ObserverInterface
{
    private $clientResolver;
    private $engineResolver;
    private $openSearchConfig;
    private $logger;

    public function __construct(
        ClientResolver $clientResolver,
        EngineResolverInterface $engineResolver,
        OpenSearchConfig $openSearchConfig,
        LoggerInterface $logger
    ) {
        $this->clientResolver = $clientResolver;
        $this->engineResolver = $engineResolver;
        $this->openSearchConfig = $openSearchConfig;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            $product = $observer->getEvent()->getProduct();
            $productData = $product->getData();

            $engine = $this->engineResolver->getCurrentSearchEngine();
            $searchClient = $this->clientResolver->create($engine);
            $indexName = $this->openSearchConfig->getIndexName();

            $searchClient->getOpenSearchClient()->index([
                'index' => $indexName,
                'id'    => (string)$product->getId(),
                'body'  => $productData
            ]);
        } catch (\Exception $e) {
            $this->logger->error('UpdateIndex error: ' . $e->getMessage());
        }
    }
}
