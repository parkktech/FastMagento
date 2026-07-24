<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Controller\Adminhtml\Efficiency;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use ParkkTech\FastMagento\Model\Efficiency\DismissStorage;

/**
 * Clears (dismisses) one reported N+1 hotspot from the monitor so a fixed/accepted loop drops off
 * the list and only the remaining work stays visible. POST-only; the finding key comes from the row.
 */
class Dismiss extends Action implements HttpPostActionInterface
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
        $key = (string) $this->getRequest()->getParam('key', '');
        if ($key !== '') {
            $this->dismissStorage->dismiss($key);
            $this->messageManager->addSuccessMessage(__('Hotspot cleared. It will stay hidden until you restore it.'));
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
