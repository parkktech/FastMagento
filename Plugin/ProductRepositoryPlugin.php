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
        if ($this->state->getAreaCode() != 'frontend') {
            return $proceed($sku, $editMode, $storeId, $forceReload);
        }

        // For frontend, try to fetch from OpenSearch by SKU
        // Fall back to native repository if not found
        try {
            $original = $proceed($sku, $editMode, $storeId, $forceReload);
            return $original;
        } catch (NoSuchEntityException $e) {
            throw $e;
        }
    }

    /**
     * Intercept getList(SearchCriteriaInterface $searchCriteria) => SearchResultsInterface
     * For frontend, we can optimize by using OpenSearch data if needed.
     * For now, fall back to the native implementation.
     */
    public function aroundGetList(
        ProductRepositoryInterface $subject,
        callable $proceed,
        \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
    ) {
        if ($this->state->getAreaCode() != 'frontend') {
            return $proceed($searchCriteria);
        }

        // For frontend, use native implementation for now
        // TODO: Optimize with OpenSearch data if needed
        return $proceed($searchCriteria);
    }
}
