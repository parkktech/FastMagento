<?php

namespace ParkkTech\FastMagento\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    const ADMIN_RESOURCE = 'ParkkTech_FastMagento::config';

    private $resultPageFactory;

    public function __construct(Context $context, PageFactory $resultPageFactory)
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('ParkkTech_FastMagento::config');
        $resultPage->getConfig()->getTitle()->prepend(__('FastMagento Settings'));
        return $resultPage;
    }
}
