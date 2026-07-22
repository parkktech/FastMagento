<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Adminhtml;

use Magento\Catalog\Controller\Adminhtml\Product\Attribute\Save;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;

/**
 * Safety net for the paginated option manager: when attribute pagination is enabled, strip the
 * monolithic option payload from the attribute Save request so the native "save the whole option
 * array" path can never run — options are managed exclusively (and per-row) through the manager's
 * AJAX endpoints. Because the paginated UI never posts these params, this is belt-and-suspenders:
 * it guarantees a Save Attribute click can never delete or overwrite the (possibly huge) option set.
 */
class AttributeSaveOptionGuardPlugin
{
    private const OPTION_PARAMS = [
        'option', 'optiondefault', 'default',
        'optionvisual', 'defaultvisual', 'swatchvisual',
        'optiontext', 'defaulttext', 'swatchtext',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function beforeExecute(Save $subject): void
    {
        if (!$this->scopeConfig->isSetFlag('fastmagento/attribute_pagination/enabled')) {
            return;
        }
        /** @var RequestInterface $request */
        $request = $subject->getRequest();
        foreach (self::OPTION_PARAMS as $param) {
            if ($request->getParam($param) !== null) {
                $request->setParams([$param => null] + $request->getParams());
                $request->setParam($param, null);
            }
        }
    }
}
