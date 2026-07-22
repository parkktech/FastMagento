<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\AttributeOption;

use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Row-at-a-time reader/writer for a product attribute's option set, built for attributes with
 * tens of thousands of options where Magento's native "Manage Options" block (which loads and
 * re-saves the whole collection) is unusable.
 *
 * - getPage(): one bounded SELECT (LIMIT/OFFSET, optional label search) — never loads the whole set.
 * - save()/delete(): touch ONLY the affected option's rows (option + per-store value + swatch),
 *   so adding/removing/editing an option is O(1), not O(all options).
 *
 * Uses direct SQL (options are simple relational rows) so it stays fast at 50k+ options and
 * handles the third-party `group_id` column on eav_attribute_option. Swatch attributes get their
 * eav_attribute_option_swatch rows kept in sync (visual = colour hex / image, text = label).
 */
class OptionRepository
{
    /** swatch type ids in eav_attribute_option_swatch */
    private const SWATCH_TEXT = 0;
    private const SWATCH_VISUAL = 1;
    private const SWATCH_IMAGE = 2;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly ReinitableConfigInterface $reinitableConfig
    ) {
    }

    /**
     * One page of options for the attribute, newest-independent (ordered by sort_order then id).
     *
     * @return array{total:int, page:int, page_size:int, options:array<int,array<string,mixed>>, stores:array<int,string>, is_swatch:bool, swatch_type:string}
     * @throws LocalizedException
     */
    public function getPage(int $attributeId, int $page = 1, int $pageSize = 50, string $search = ''): array
    {
        $attribute = $this->attribute($attributeId);
        $conn = $this->resource->getConnection();
        $tOpt = $this->resource->getTableName('eav_attribute_option');
        $tVal = $this->resource->getTableName('eav_attribute_option_value');
        $page = max(1, $page);
        $pageSize = max(1, min(200, $pageSize));

        // total (with optional admin-label search)
        $countSel = $conn->select()->from(['o' => $tOpt], ['n' => 'COUNT(*)'])->where('o.attribute_id = ?', $attributeId);
        if ($search !== '') {
            $countSel->join(['v' => $tVal], 'v.option_id = o.option_id AND v.store_id = 0', [])
                ->where('v.value LIKE ?', '%' . $search . '%');
        }
        $total = (int) $conn->fetchOne($countSel);

        // page of option ids (bounded — this is what keeps the page load flat)
        $idSel = $conn->select()->from(['o' => $tOpt], ['o.option_id', 'o.sort_order'])
            ->where('o.attribute_id = ?', $attributeId)
            ->order('o.sort_order ASC')->order('o.option_id ASC')
            ->limit($pageSize, ($page - 1) * $pageSize);
        if ($search !== '') {
            $idSel->join(['v' => $tVal], 'v.option_id = o.option_id AND v.store_id = 0', [])
                ->where('v.value LIKE ?', '%' . $search . '%');
        }
        $rows = $conn->fetchAll($idSel);
        $optionIds = array_map(static fn ($r) => (int) $r['option_id'], $rows);

        $stores = $this->stores();
        $isSwatch = $this->isSwatch($attribute);
        $options = [];
        if ($optionIds) {
            $labels = $this->labelsByOption($optionIds);              // [option_id][store_id] => value
            $swatches = $isSwatch ? $this->swatchesByOption($optionIds) : [];
            $default = $this->defaultOptionIds($attributeId);
            foreach ($rows as $r) {
                $oid = (int) $r['option_id'];
                $options[] = [
                    'option_id' => $oid,
                    'sort_order' => (int) $r['sort_order'],
                    'labels' => array_map(fn ($sid) => (string) ($labels[$oid][$sid] ?? ''), array_keys($stores)),
                    'admin_label' => (string) ($labels[$oid][0] ?? ''),
                    'swatch' => $swatches[$oid] ?? null,
                    'is_default' => in_array($oid, $default, true),
                ];
            }
        }

        return [
            'total' => $total, 'page' => $page, 'page_size' => $pageSize,
            'options' => $options, 'stores' => $stores,
            'is_swatch' => $isSwatch, 'swatch_type' => $this->swatchType($attribute),
        ];
    }

    /**
     * Insert or update ONE option (its admin/store labels and, for swatch attributes, its swatch
     * value). Returns the saved option_id. Only this option's rows are written.
     *
     * @param array<int,string> $labels store_id => label (0 = admin, required)
     * @throws LocalizedException
     */
    public function save(int $attributeId, ?int $optionId, array $labels, int $sortOrder = 0, ?string $swatchValue = null, bool $isDefault = false): int
    {
        $attribute = $this->attribute($attributeId);
        $adminLabel = trim((string) ($labels[0] ?? ''));
        if ($adminLabel === '') {
            throw new LocalizedException(__('An admin label is required.'));
        }
        $conn = $this->resource->getConnection();
        $tOpt = $this->resource->getTableName('eav_attribute_option');
        $tVal = $this->resource->getTableName('eav_attribute_option_value');

        $conn->beginTransaction();
        try {
            if ($optionId) {
                $conn->update($tOpt, ['sort_order' => $sortOrder], ['option_id = ?' => $optionId, 'attribute_id = ?' => $attributeId]);
            } else {
                // include group_id (0) for schemas that added the NOT-NULL column
                $data = ['attribute_id' => $attributeId, 'sort_order' => $sortOrder];
                if ($conn->tableColumnExists($tOpt, 'group_id')) {
                    $data['group_id'] = 0;
                }
                $conn->insert($tOpt, $data);
                $optionId = (int) $conn->lastInsertId($tOpt);
            }

            // labels: upsert per store; drop a store label that was cleared
            foreach ($this->stores() as $storeId => $_name) {
                $value = trim((string) ($labels[$storeId] ?? ''));
                $existing = $conn->fetchOne(
                    $conn->select()->from($tVal, 'value_id')->where('option_id = ?', $optionId)->where('store_id = ?', $storeId)
                );
                if ($value === '' && $storeId !== 0) {
                    if ($existing) { $conn->delete($tVal, ['value_id = ?' => $existing]); }
                    continue;
                }
                if ($existing) {
                    $conn->update($tVal, ['value' => $value], ['value_id = ?' => $existing]);
                } else {
                    $conn->insert($tVal, ['option_id' => $optionId, 'store_id' => $storeId, 'value' => $value]);
                }
            }

            if ($this->isSwatch($attribute)) {
                $this->saveSwatch($attributeId, $optionId, $swatchValue, $this->swatchType($attribute));
            }
            if ($isDefault) {
                $this->setDefault($attributeId, $optionId);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw new LocalizedException(__('Could not save option: %1', $e->getMessage()));
        }

        $this->reinitableConfig->reinit();
        return $optionId;
    }

    /**
     * Delete ONE option and its dependent rows (values + swatch). Only this option is touched.
     *
     * @throws LocalizedException
     */
    public function delete(int $attributeId, int $optionId): void
    {
        $this->attribute($attributeId); // validates the attribute exists / is EAV
        $conn = $this->resource->getConnection();
        // guard: the option must belong to this attribute
        $owner = (int) $conn->fetchOne(
            $conn->select()->from($this->resource->getTableName('eav_attribute_option'), 'attribute_id')->where('option_id = ?', $optionId)
        );
        if ($owner !== $attributeId) {
            throw new LocalizedException(__('Option %1 does not belong to this attribute.', $optionId));
        }
        $conn->beginTransaction();
        try {
            $conn->delete($this->resource->getTableName('eav_attribute_option_value'), ['option_id = ?' => $optionId]);
            $conn->delete($this->resource->getTableName('eav_attribute_option_swatch'), ['option_id = ?' => $optionId]);
            $conn->delete($this->resource->getTableName('eav_attribute_option'), ['option_id = ?' => $optionId]);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw new LocalizedException(__('Could not delete option: %1', $e->getMessage()));
        }
        $this->reinitableConfig->reinit();
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────

    private function saveSwatch(int $attributeId, int $optionId, ?string $value, string $swatchType): void
    {
        $conn = $this->resource->getConnection();
        $tSw = $this->resource->getTableName('eav_attribute_option_swatch');
        $type = $swatchType === 'visual' ? self::SWATCH_VISUAL : self::SWATCH_TEXT;
        // a visual swatch value that points at a file is an image swatch
        if ($type === self::SWATCH_VISUAL && $value !== null && $value !== '' && $value[0] === '/') {
            $type = self::SWATCH_IMAGE;
        }
        foreach ($this->stores() as $storeId => $_n) {
            $existing = $conn->fetchOne(
                $conn->select()->from($tSw, 'swatch_id')->where('option_id = ?', $optionId)->where('store_id = ?', $storeId)
            );
            $row = ['type' => $type, 'value' => (string) ($value ?? '')];
            if ($existing) {
                $conn->update($tSw, $row, ['swatch_id = ?' => $existing]);
            } else {
                $conn->insert($tSw, $row + ['option_id' => $optionId, 'store_id' => $storeId]);
            }
        }
    }

    private function setDefault(int $attributeId, int $optionId): void
    {
        // default_value on eav_attribute stores the default option id(s)
        $conn = $this->resource->getConnection();
        $conn->update($this->resource->getTableName('eav_attribute'), ['default_value' => (string) $optionId], ['attribute_id = ?' => $attributeId]);
    }

    /** @param int[] $optionIds @return array<int,array<int,string>> */
    private function labelsByOption(array $optionIds): array
    {
        $conn = $this->resource->getConnection();
        $rows = $conn->fetchAll(
            $conn->select()->from($this->resource->getTableName('eav_attribute_option_value'), ['option_id', 'store_id', 'value'])
                ->where('option_id IN (?)', $optionIds)
        );
        $out = [];
        foreach ($rows as $r) { $out[(int) $r['option_id']][(int) $r['store_id']] = (string) $r['value']; }
        return $out;
    }

    /** @param int[] $optionIds @return array<int,array{type:int,value:string}> */
    private function swatchesByOption(array $optionIds): array
    {
        $conn = $this->resource->getConnection();
        $tSw = $this->resource->getTableName('eav_attribute_option_swatch');
        if (!$conn->isTableExists($tSw)) { return []; }
        $rows = $conn->fetchAll(
            $conn->select()->from($tSw, ['option_id', 'type', 'value'])->where('option_id IN (?)', $optionIds)->where('store_id = 0')
        );
        $out = [];
        foreach ($rows as $r) { $out[(int) $r['option_id']] = ['type' => (int) $r['type'], 'value' => (string) $r['value']]; }
        return $out;
    }

    /** @return int[] */
    private function defaultOptionIds(int $attributeId): array
    {
        $conn = $this->resource->getConnection();
        $raw = (string) $conn->fetchOne(
            $conn->select()->from($this->resource->getTableName('eav_attribute'), 'default_value')->where('attribute_id = ?', $attributeId)
        );
        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    /** @return array<int,string> store_id => label (0 = Admin) */
    private function stores(): array
    {
        $conn = $this->resource->getConnection();
        $stores = [0 => 'Admin'];
        foreach ($conn->fetchAll($conn->select()->from($this->resource->getTableName('store'), ['store_id', 'name'])->where('store_id > 0')->order('store_id')) as $s) {
            $stores[(int) $s['store_id']] = (string) $s['name'];
        }
        return $stores;
    }

    private function attribute(int $attributeId): AbstractAttribute
    {
        $conn = $this->resource->getConnection();
        $code = (string) $conn->fetchOne(
            $conn->select()->from($this->resource->getTableName('eav_attribute'), 'attribute_code')->where('attribute_id = ?', $attributeId)
        );
        if ($code === '') {
            throw new LocalizedException(__('Attribute %1 not found.', $attributeId));
        }
        /** @var AbstractAttribute $attr */
        $attr = $this->attributeRepository->get(\Magento\Catalog\Api\Data\ProductAttributeInterface::ENTITY_TYPE_CODE, $code);
        return $attr;
    }

    private function isSwatch(AbstractAttribute $attribute): bool
    {
        return in_array($this->swatchType($attribute), ['visual', 'text'], true);
    }

    private function swatchType(AbstractAttribute $attribute): string
    {
        $additional = $attribute->getData('additional_data');
        if ($additional) {
            $decoded = json_decode((string) $additional, true);
            $t = $decoded['swatch_input_type'] ?? '';
            if ($t === 'visual' || $t === 'text') { return $t; }
        }
        return '';
    }
}
