<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Controller\Adminhtml\AttributeOption;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use ParkkTech\FastMagento\Model\AttributeOption\OptionRepository;

/**
 * Admin AJAX: insert or update ONE attribute option (labels + optional swatch value). Writes only
 * this option's rows — the whole option set is never rewritten — so it stays fast at 50k+ options.
 * POST-only; form key + ACL enforced by the admin router.
 */
class Save extends Action implements HttpPostActionInterface
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
            $req = $this->getRequest();
            $labels = (array) $req->getParam('labels', []);
            $labels = array_map(static fn ($v) => (string) $v, $labels);   // {store_id => value}
            $optionId = (int) $req->getParam('option_id', 0);

            $savedId = $this->options->save(
                (int) $req->getParam('attribute_id'),
                $optionId ?: null,
                $labels,
                (int) $req->getParam('sort_order', 0),
                $req->getParam('swatch_value') !== null ? (string) $req->getParam('swatch_value') : null,
                (bool) $req->getParam('is_default', false)
            );
            return $result->setData(['success' => true, 'option_id' => $savedId]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
