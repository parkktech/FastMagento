<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Block\Adminhtml\AttributeOption;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ResourceModel\Store\CollectionFactory as StoreCollectionFactory;

/**
 * Paginated option manager shown on the product-attribute edit page in place of Magento's native
 * "Manage Options" / swatch grids. Renders an (almost) empty container; the JS fetches one page of
 * options at a time from the AJAX endpoints, so an attribute with tens of thousands of options
 * loads instantly. Works for every option-bearing type: dropdown, multiple select, visual swatch,
 * text swatch.
 */
class Manager extends Template
{
    protected $_template = 'ParkkTech_FastMagento::attribute-option/manager.phtml';

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly Json $json,
        private readonly StoreCollectionFactory $storeCollectionFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Render only for enabled option-bearing attributes (select / multiselect / swatch).
     */
    public function shouldRender(): bool
    {
        if (!$this->scopeConfig->isSetFlag('fastmagento/attribute_pagination/enabled')) {
            return false;
        }
        $attribute = $this->registry->registry('entity_attribute');
        if (!$attribute || !$attribute->getId()) {
            return false;
        }
        return in_array($attribute->getFrontendInput(), ['select', 'multiselect'], true);
    }

    public function getConfigJson(): string
    {
        $attribute = $this->registry->registry('entity_attribute');
        $swatchType = $this->swatchType($attribute);

        return $this->json->serialize([
            'attributeId' => (int) $attribute->getId(),
            'attributeCode' => (string) $attribute->getAttributeCode(),
            'frontendInput' => (string) $attribute->getFrontendInput(),
            'isSwatch' => $swatchType !== '',
            'swatchType' => $swatchType,           // 'visual' | 'text' | ''
            'stores' => $this->stores(),           // [{id,label}]
            'pageSize' => (int) ($this->scopeConfig->getValue('fastmagento/attribute_pagination/page_size') ?: 50),
            'formKey' => $this->getFormKey(),
            'gridUrl' => $this->getUrl('fastmagento/attributeOption/grid'),
            'saveUrl' => $this->getUrl('fastmagento/attributeOption/save'),
            'deleteUrl' => $this->getUrl('fastmagento/attributeOption/delete'),
        ]);
    }

    /**
     * @return array<int,array{id:int,label:string}>
     */
    private function stores(): array
    {
        $stores = [['id' => 0, 'label' => (string) __('Admin')]];
        $collection = $this->storeCollectionFactory->create()->setLoadDefault(false);
        foreach ($collection as $store) {
            $stores[] = ['id' => (int) $store->getId(), 'label' => $store->getName()];
        }
        return $stores;
    }

    private function swatchType($attribute): string
    {
        $additional = $attribute->getData('additional_data');
        if ($additional) {
            try {
                $decoded = $this->json->unserialize((string) $additional);
            } catch (\Throwable $e) {
                $decoded = [];
            }
            $t = $decoded['swatch_input_type'] ?? '';
            if ($t === 'visual' || $t === 'text') {
                return $t;
            }
        }
        return '';
    }
}
