<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Generate thesaurus from catalogue" button on the FastMagento AI settings page. Posts to the
 * admin AJAX endpoint, which reads the store's own attribute values + categories, asks Claude to
 * build shopper-facing synonym groups, and merges them into Search > Synonyms. The page reloads
 * so the updated Synonyms field is shown for review before saving.
 */
class GenerateThesaurusButton extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $url = $this->getUrl('fastmagento/ai/generateThesaurus');
        $formKey = $this->getFormKey();
        $buttonId = 'fastmagento_generate_thesaurus';

        $html = <<<HTML
<button type="button" id="{$buttonId}" class="action-default scalable">
    <span>Generate thesaurus from catalogue</span>
</button>
<span id="{$buttonId}_status" style="margin-left:12px;color:#666;"></span>
<div id="{$buttonId}_preview" style="margin-top:10px;white-space:pre-wrap;color:#333;"></div>
<script>
require(['jquery'], function (\$) {
    \$('#{$buttonId}').on('click', function () {
        var \$btn = \$(this), \$status = \$('#{$buttonId}_status'), \$preview = \$('#{$buttonId}_preview');
        if (\$btn.prop('disabled')) { return; }
        \$btn.prop('disabled', true);
        \$status.css('color', '#666').text('Reading your catalogue and generating synonyms… this can take up to a minute.');
        \$preview.text('');
        \$.ajax({
            url: '{$url}',
            type: 'POST',
            dataType: 'json',
            data: { form_key: '{$formKey}' }
        }).done(function (res) {
            if (res && res.success) {
                \$status.css('color', '#1a7f37').text(res.message);
                if (res.preview && res.preview.length) {
                    \$preview.text('New groups:\\n' + res.preview.join('\\n'));
                }
                setTimeout(function () { window.location.reload(); }, 2500);
            } else {
                \$status.css('color', '#b3261e').text((res && res.message) ? res.message : 'Generation failed.');
                \$btn.prop('disabled', false);
            }
        }).fail(function () {
            \$status.css('color', '#b3261e').text('Request failed. Check the server logs.');
            \$btn.prop('disabled', false);
        });
    });
});
</script>
HTML;

        return $html;
    }
}
