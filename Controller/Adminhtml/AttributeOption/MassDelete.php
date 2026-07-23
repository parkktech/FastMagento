<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Controller\Adminhtml\AttributeOption;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use ParkkTech\FastMagento\Model\AttributeOption\OptionRepository;

/**
 * Admin AJAX: bulk-delete attribute options. Two modes:
 *   - mode=selected → delete the explicitly checked option ids (option_ids[]).
 *   - mode=all      → delete EVERY option matching the current search + assignment filter (across
 *                     all pages), e.g. "all unassigned options".
 * POST-only; form key + ACL enforced by the admin router. Deletes are chunked in the repository so
 * clearing tens of thousands of unused options stays within sane transaction sizes.
 */
class MassDelete extends Action implements HttpPostActionInterface
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
        $attributeId = (int) $request->getParam('attribute_id');
        $mode = (string) $request->getParam('mode', 'selected');

        try {
            if ($mode === 'all') {
                $deleted = $this->options->deleteAllMatching(
                    $attributeId,
                    trim((string) $request->getParam('search', '')),
                    (string) $request->getParam('assigned', '')
                );
            } else {
                $ids = $request->getParam('option_ids', []);
                if (!is_array($ids)) {
                    $ids = array_filter(explode(',', (string) $ids));
                }
                $deleted = $this->options->deleteMany($attributeId, array_map('intval', $ids));
            }
            return $result->setData(['success' => true, 'deleted' => $deleted]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
