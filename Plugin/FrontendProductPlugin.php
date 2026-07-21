<?php

namespace ParkkTech\FastMagento\Plugin;

use Magento\Framework\App\State;
use Magento\Catalog\Model\Product;
use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagento\Helper\ShellProductBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

class FrontendProductPlugin
{
    /**
     * @param WriteLog $writeLog
     * @param State $appState
     * @param ShellProductBuilder $shellProductBuilder
     * @param OpenSearchPdpFetcher $openSearchPdpFetcher
     */
    public function __construct(
        private readonly WriteLog $writeLog,
        private readonly State $appState,
        private readonly ShellProductBuilder $shellProductBuilder,
        private readonly OpenSearchPdpFetcher $openSearchPdpFetcher
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

            $doc = $this->openSearchPdpFetcher->fetchPdpById($modelId);
            if (!$doc) {
                $this->writeLog->writeErrorLog('Product With ID: ' . $modelId . ' Does Not Exist in Open Search.');
                throw new NoSuchEntityException(__('Product ID Does Not Exist'));
            }

            return $this->shellProductBuilder->buildNoEavProductFromOsDoc($doc);

        }

        return $proceed($modelId, $field);
    }
}
