<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Controller\Adminhtml\AttributeOption;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use ParkkTech\FastMagento\Model\AttributeOption\OptionRepository;

/**
 * Admin AJAX: delete ONE attribute option (and its per-store value + swatch rows). Touches only
 * this option — the rest of the (possibly huge) option set is untouched. POST-only; form key +
 * ACL enforced by the admin router.
 */
class Delete extends Action implements HttpPostActionInterface
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
            $this->options->delete(
                (int) $this->getRequest()->getParam('attribute_id'),
                (int) $this->getRequest()->getParam('option_id')
            );
            return $result->setData(['success' => true]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
