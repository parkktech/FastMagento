<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Eav;

use Magento\Eav\Model\Entity\Attribute\Source\Table;
use ParkkTech\FastMagento\Model\OptionDictionary;

/**
 * Serve select/multiselect option labels from the OpenSearch dictionary instead of MySQL.
 *
 * Wired frontend-only (etc/frontend/di.xml) so admin/import/cron keep the native source. Both
 * methods fall straight through to native on any dictionary miss, so a stale/absent dictionary is
 * never a correctness risk — only a slower (MySQL) path. This removes the residual
 * eav_attribute_option(_value) loads on served PDP additional-attributes, layered-nav facets and
 * swatch rendering.
 */
class SourceOptionPlugin
{
    public function __construct(private readonly OptionDictionary $dictionary)
    {
    }

    /**
     * @param callable $proceed
     * @return array<int,array<string,mixed>>
     */
    public function aroundGetAllOptions(Table $subject, callable $proceed, $withEmpty = true, $defaultValues = false)
    {
        $attributeId = $this->attributeId($subject);
        if ($attributeId <= 0) {
            return $proceed($withEmpty, $defaultValues);
        }
        $options = $this->dictionary->getOptions($attributeId);
        if (!$options) {
            return $proceed($withEmpty, $defaultValues);
        }
        $out = [];
        if ($withEmpty) {
            $out[] = ['label' => ' ', 'value' => ''];
        }
        foreach ($options as $o) {
            $out[] = ['label' => $o['label'], 'value' => $o['value']];
        }
        return $out;
    }

    /**
     * @param callable $proceed
     * @param string|int $value
     * @return string|false|array
     */
    public function aroundGetOptionText(Table $subject, callable $proceed, $value)
    {
        // Multi-value (multiselect): resolve each id from the dictionary and return the label array
        // (native returns an array here). Any unresolved id → fall back to native for the whole set.
        if (is_array($value) || (is_string($value) && strpos($value, ',') !== false)) {
            $attributeId = $this->attributeId($subject);
            if ($attributeId <= 0) {
                return $proceed($value);
            }
            $ids = is_array($value) ? $value : explode(',', (string) $value);
            $labels = [];
            foreach ($ids as $id) {
                $id = trim((string) $id);
                if ($id === '') {
                    continue;
                }
                if (ctype_digit($id)) {
                    $label = $this->dictionary->getOptionText($attributeId, $id);
                    if ($label === null) {
                        return $proceed($value); // dictionary miss — native for the whole value
                    }
                    $labels[] = $label;
                } else {
                    $labels[] = $id; // already a resolved label (shell)
                }
            }
            return $labels;
        }
        $v = (string) $value;
        // No value = no label. Native would run getSpecificOptions('') (a full option-collection
        // load) only to return false — short-circuit it. The Attributes block renders every visible
        // attribute in the set, so empty select values are the common case and the main cost.
        if ($v === '') {
            return false;
        }
        $attributeId = $this->attributeId($subject);
        if ($attributeId <= 0) {
            return $proceed($value);
        }
        // Numeric value = an option id → resolve its label from the dictionary.
        if (ctype_digit($v)) {
            $label = $this->dictionary->getOptionText($attributeId, $v);
            return $label === null ? $proceed($value) : $label;
        }
        // Non-numeric value = a FastMagento shell already storing the resolved LABEL as the value
        // (the OpenSearch doc stores labels). It is already display text — echo it back rather than
        // firing getSpecificOptions() against MySQL to (fail to) re-resolve a label as an id.
        return $v;
    }

    private function attributeId(Table $subject): int
    {
        try {
            $attribute = $subject->getAttribute();
            return $attribute ? (int) $attribute->getAttributeId() : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
