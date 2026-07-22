<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Controller\Adminhtml\AttributeOption;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use ParkkTech\FastMagento\Model\AttributeOption\OptionRepository;

/**
 * Admin AJAX: return ONE page of an attribute's options as JSON. Backs the paginated option
 * manager so an attribute with tens of thousands of options loads only the requested page.
 * Read-only; ACL-gated to the native attribute permission.
 */
class Grid extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Catalog::attributes_attributes';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly OptionRepository $options
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $data = $this->options->getPage(
                (int) $this->getRequest()->getParam('attribute_id'),
                (int) $this->getRequest()->getParam('page', 1),
                (int) $this->getRequest()->getParam('page_size', 50),
                trim((string) $this->getRequest()->getParam('search', ''))
            );
            return $result->setData(['success' => true] + $data);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
