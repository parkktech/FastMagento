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
        $request = $this->getRequest();
        try {
            $attributeId = (int) $request->getParam('attribute_id');
            $search = trim((string) $request->getParam('search', ''));
            $assigned = (string) $request->getParam('assigned', '');
            $data = $this->options->getPage(
                $attributeId,
                (int) $request->getParam('page', 1),
                (int) $request->getParam('page_size', 50),
                $search,
                $assigned
            );
            // Opt-in (used only when opening the "delete all matching" confirm): how many of the
            // matched options are still assigned to a product, so the UI can warn precisely.
            if ($request->getParam('counts')) {
                $data['assigned_in_match'] = $this->options->countAssignedInMatch($attributeId, $search, $assigned);
            }
            return $result->setData(['success' => true] + $data);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
