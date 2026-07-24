<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Controller\Adminhtml\Efficiency;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use ParkkTech\FastMagento\Model\Efficiency\DismissStorage;

/**
 * Restores every dismissed hotspot back onto the monitor (undo "clear all").
 */
class Restore extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'ParkkTech_FastMagento::efficiency';

    public function __construct(
        Context $context,
        private readonly DismissStorage $dismissStorage
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $this->dismissStorage->clearAll();
        $this->messageManager->addSuccessMessage(__('All cleared hotspots have been restored.'));
        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
