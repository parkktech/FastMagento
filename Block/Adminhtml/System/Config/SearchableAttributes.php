<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Editable "Searchable Attributes" table on the FastMagento Search settings page: manage the
 * attribute -> search weight map in ONE place (Magento otherwise hides search_weight inside
 * each attribute edit form). Higher weight ranks matches on that attribute higher. Consumed
 * by Model/Search/RelevanceConfig to build the OpenSearch multi_match field boosts.
 */
class SearchableAttributes extends AbstractFieldArray
{
    private ?AttributeColumn $attributeRenderer = null;

    protected function _prepareToRender(): void
    {
        $this->addColumn('attribute', [
            'label' => __('Attribute'),
            'renderer' => $this->getAttributeRenderer(),
        ]);
        $this->addColumn('weight', ['label' => __('Search Weight'), 'class' => 'validate-number']);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add Attribute');
    }

    protected function _prepareArrayRow(\Magento\Framework\DataObject $row): void
    {
        $attribute = $row->getAttribute();
        $options = [];
        if ($attribute) {
            $options['option_' . $this->getAttributeRenderer()->calcOptionHash($attribute)] = 'selected="selected"';
        }
        $row->setData('option_extra_attrs', $options);
    }

    private function getAttributeRenderer(): AttributeColumn
    {
        if (!$this->attributeRenderer) {
            $this->attributeRenderer = $this->getLayout()->createBlock(
                AttributeColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->attributeRenderer;
    }
}
