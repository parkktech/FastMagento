<?php

namespace ParkkTech\FastMagento\Plugin;

use Magento\Framework\App\State;
use ParkkTech\FastMagento\Helper\WriteLog;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use ParkkTech\FastMagento\Helper\ShellProductBuilder;
use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;

/**
 * A plugin that intercepts ProductRepository calls and converts
 * the returned ProductInterface(s) into your ShellProduct model.
 */
class ProductRepositoryPlugin
{
    /**
     * @param State $state
     * @param WriteLog $writeLog
     * @param ShellProductBuilder $shellProductBuilder
     * @param OpenSearchPdpFetcher $openSearchPdpFetcher
     */
    public function __construct(
        private readonly State $state,
        private readonly WriteLog $writeLog,
        private readonly ShellProductBuilder $shellProductBuilder,
        private readonly OpenSearchPdpFetcher $openSearchPdpFetcher
    ) {
    }

    /**
     * Intercept getById($productId, $editMode = false, $storeId = null, $forceReload = false)
     * to return a ShellProduct instead of a plain product.
     */
    public function aroundGetById(
        ProductRepositoryInterface $subject,
        callable $proceed,
        $productId,
        $editMode = false,
        $storeId = null,
        $forceReload = false
    ) {
        if ($this->state->getAreaCode() != 'frontend') {
            return $proceed($productId, $editMode, $storeId, $forceReload);
        }

        $doc = $this->openSearchPdpFetcher->fetchPdpById($productId);
        if (!$doc) {
            $this->writeLog->writeErrorLog('Product With ID: ' . $productId . ' Does Not Exist in Open Search.');
            throw new NoSuchEntityException(__('Product ID Does Not Exist'));
        }

        return $this->shellProductBuilder->buildNoEavProductFromOsDoc($doc);
    }

    /**
     * Intercept get($sku, $editMode = false, $storeId = null, $forceReload = false)
     */
    public function aroundGet(
        ProductRepositoryInterface $subject,
        callable $proceed,
                                   $sku,
                                   $editMode = false,
                                   $storeId = null,
                                   $forceReload = false
    ) {
        // 1) Original
        $original = $proceed($sku, $editMode, $storeId, $forceReload);

        // 2) Convert
        $shell = $this->shellProductBuilder->convertToShellProduct($original);

        return $shell;
    }

    /**
     * Intercept getList(SearchCriteriaInterface $searchCriteria) => SearchResultsInterface
     * We must convert each product in the returned result to a ShellProduct.
     */
    public function aroundGetList(
        ProductRepositoryInterface $subject,
        callable $proceed,
        \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
    ) {
        /** @var SearchResultsInterface $searchResults */
        $searchResults = $proceed($searchCriteria);

        // Loop through all items, convert each to ShellProduct
        $items = $searchResults->getItems(); // array of ProductInterface
        foreach ($items as $key => $productInterface) {
            $shell = $this->shellProductBuilder->convertToShellProduct($productInterface);
            $items[$key] = $shell;
        }

        // Replace the items in the search result
        $searchResults->setItems($items);

        // Return the updated search results
        return $searchResults;
    }
}
