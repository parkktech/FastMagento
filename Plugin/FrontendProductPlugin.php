<?php

namespace ParkkTech\FastMagento\Plugin;

use Magento\Framework\App\State;
use Magento\Catalog\Model\Product;
use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagento\Helper\ShellProductBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;
use ParkkTech\FastMagento\Model\Indexer\ProductIndexer;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

class FrontendProductPlugin
{
    /**
     * @param WriteLog $writeLog
     * @param State $appState
     * @param ShellProductBuilder $shellProductBuilder
     * @param OpenSearchPdpFetcher $openSearchPdpFetcher
     * @param ProductIndexer $productIndexer
     */
    public function __construct(
        private readonly WriteLog $writeLog,
        private readonly State $appState,
        private readonly ShellProductBuilder $shellProductBuilder,
        private readonly OpenSearchPdpFetcher $openSearchPdpFetcher,
        private readonly ProductIndexer $productIndexer
    ) {
    }

    /**
     * Around Plugin: Swap product model with ShellNoEavProduct in frontend.
     */
    /**
     * @param Product $subject
     * @param callable $proceed
     * @param $modelId
     * @param $field
     * @return ShellNoEavProduct
     * @throws NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function aroundLoad(Product $subject, callable $proceed, $modelId, $field = null)
    {
        // Only modify product model in the frontend scope
        if ($this->appState->getAreaCode() === 'frontend') {
            if (null != $field) {
                return $proceed($modelId, $field);
            }

            // Empty/zero id = a freshly instantiated product object (widgets, factories).
            // Don't fire an OpenSearch lookup for id 0 — it always misses and, on pages
            // like the homepage, produced hundreds of wasteful OS fetches + warm attempts.
            if (empty($modelId)) {
                return $proceed($modelId, $field);
            }

            $doc = $this->openSearchPdpFetcher->fetchPdpById($modelId);
            if (!$doc) {
                // Warm-on-miss (read-through, like a cache): the product isn't in
                // OpenSearch yet (mid-reindex / stale / never indexed). Load it natively
                // once, PUSH it into OpenSearch, then serve it FROM OpenSearch — so the
                // serving layer stays OS-only and the next request is a pure OS read.
                $native = $proceed($modelId, $field);
                if ($native && $native->getId()) {
                    $this->productIndexer->indexProductObject($native);
                    $doc = $this->openSearchPdpFetcher->fetchPdpById($modelId);
                }
                if (!$doc) {
                    // OpenSearch still unavailable (e.g. OS down) — degrade to native so
                    // the store is never harder-down than base Magento.
                    $this->writeLog->writeErrorLog(
                        'Product ID ' . $modelId . ' could not be warmed into OpenSearch; serving native.'
                    );
                    return $native ?? $proceed($modelId, $field);
                }
            }

            return $this->shellProductBuilder->buildNoEavProductFromOsDoc($doc);

        }

        return $proceed($modelId, $field);
    }
}
