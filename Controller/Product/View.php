<?php

namespace ParkkTech\FastMagento\Controller\Product;

use Magento\Catalog\Controller\Product\View as MagentoView;
use Magento\Framework\App\Action\Context;
use Magento\Catalog\Helper\Product\View as ViewHelper;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\View\Result\PageFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\Json\Helper\Data;
use Magento\Catalog\Model\Design;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Catalog\Helper\Product\View as CatalogProductView;

/**
 * Custom PDP controller that fetches doc from OpenSearch,
 * builds a no-EAV Magento\Catalog\Model\Product subclass,
 * and registers it, so Swissup/SEO sees a real product object.
 */
class View extends MagentoView
{
    /**
     * @param Context $context
     * @param CatalogProductView $viewHelper
     * @param ForwardFactory $resultForwardFactory
     * @param PageFactory $resultPageFactory
     * @param LoggerInterface|null $logger
     * @param Data|null $jsonHelper
     * @param Design|null $catalogDesign
     * @param ProductRepositoryInterface|null $productRepository
     * @param StoreManagerInterface|null $storeManager
     * @param CatalogProductView $catalogProductView
     */
    public function __construct(
        Context $context,
        ViewHelper $viewHelper,
        ForwardFactory $resultForwardFactory,
        PageFactory $resultPageFactory,
        LoggerInterface $logger = null,
        Data $jsonHelper = null,
        Design $catalogDesign = null,
        ProductRepositoryInterface $productRepository = null,
        StoreManagerInterface $storeManager = null,
        private CatalogProductView $catalogProductView
    ) {
        parent::__construct(
            $context,
            $viewHelper,
            $resultForwardFactory,
            $resultPageFactory,
            $logger,
            $jsonHelper,
            $catalogDesign,
            $productRepository,
            $storeManager
        );
    }

    /**
     * @return \Magento\Framework\Controller\Result\Forward|\Magento\Framework\Controller\Result\Redirect|\Magento\Framework\View\Result\Page
     * @throws NotFoundException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        $productId = (int)$this->getRequest()->getParam('id');
        if (!$productId) {
            throw new NotFoundException(__('No product ID param.'));
        }

        $resultPage = $this->resultPageFactory->create();
        $this->catalogProductView->prepareAndRender(
            $resultPage,
            $productId,
            $this,
            null
        );

        return $resultPage;
    }
}
