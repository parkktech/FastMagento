<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Controller\Adminhtml\Efficiency;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the Extension Efficiency monitor — a read-only dashboard of the latest scan report.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'ParkkTech_FastMagento::efficiency';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('ParkkTech_FastMagento::efficiency');
        $resultPage->getConfig()->getTitle()->prepend(__('Extension Efficiency'));
        return $resultPage;
    }
}
