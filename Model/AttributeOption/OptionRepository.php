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
    public function getPage(int $attributeId, int $page = 1, int $pageSize = 50, string $search = '', string $assigned = ''): array
    {
        $attribute = $this->attribute($attributeId);
        $conn = $this->resource->getConnection();
        $tOpt = $this->resource->getTableName('eav_attribute_option');
        $tVal = $this->resource->getTableName('eav_attribute_option_value');
        $page = max(1, $page);
        $pageSize = max(1, min(200, $pageSize));

        $source = $this->usageSource($attribute);
        // For multiselect the used option-ids live comma-packed in one column, so we parse the set
        // once and reuse it for both the filter and the per-row flag. Single-value (select/swatch)
        // attributes filter via an IN-subquery instead — no need to pull the set into PHP.
        $usedSet = $source['multiselect'] ? $this->multiselectUsedSet($attributeId, $source) : null;

        // total (respecting search + assignment filters)
        $countSel = $conn->select()->from(['o' => $tOpt], ['n' => 'COUNT(DISTINCT o.option_id)']);
        $this->applyFilters($countSel, $tVal, $search, $assigned, $attributeId, $source, $usedSet);
        $total = (int) $conn->fetchOne($countSel);

        // page of option ids (bounded — this is what keeps the page load flat)
        $idSel = $conn->select()->from(['o' => $tOpt], ['o.option_id', 'o.sort_order'])
            ->group('o.option_id')
            ->order('o.sort_order ASC')->order('o.option_id ASC')
            ->limit($pageSize, ($page - 1) * $pageSize);
        $this->applyFilters($idSel, $tVal, $search, $assigned, $attributeId, $source, $usedSet);
        $rows = $conn->fetchAll($idSel);
        $optionIds = array_map(static fn ($r) => (int) $r['option_id'], $rows);

        $stores = $this->stores();
        $isSwatch = $this->isSwatch($attribute);
        $options = [];
        if ($optionIds) {
            $labels = $this->labelsByOption($optionIds);              // [option_id][store_id] => value
            $swatches = $isSwatch ? $this->swatchesByOption($optionIds) : [];
            $default = $this->defaultOptionIds($attributeId);
            $assignedFlags = $this->assignedFlags($attributeId, $source, $optionIds, $usedSet);
            foreach ($rows as $r) {
                $oid = (int) $r['option_id'];
                $options[] = [
                    'option_id' => $oid,
                    'sort_order' => (int) $r['sort_order'],
                    'labels' => array_map(fn ($sid) => (string) ($labels[$oid][$sid] ?? ''), array_keys($stores)),
                    'admin_label' => (string) ($labels[$oid][0] ?? ''),
                    'swatch' => $swatches[$oid] ?? null,
                    'is_default' => in_array($oid, $default, true),
                    'assigned' => (bool) ($assignedFlags[$oid] ?? false),
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
     * Delete a set of options by id (each with its per-store value + swatch rows), guarded to the
     * given attribute. Chunked so removing tens of thousands of options stays within sane
     * transaction sizes. Returns the number of option rows actually removed.
     *
     * @param int[] $optionIds
     * @throws LocalizedException
     */
    public function deleteMany(int $attributeId, array $optionIds): int
    {
        $this->attribute($attributeId); // validates the attribute exists / is EAV
        $optionIds = array_values(array_unique(array_filter(array_map('intval', $optionIds))));
        if (!$optionIds) {
            return 0;
        }
        $conn = $this->resource->getConnection();
        $tOpt = $this->resource->getTableName('eav_attribute_option');
        $tVal = $this->resource->getTableName('eav_attribute_option_value');
        $tSw = $this->resource->getTableName('eav_attribute_option_swatch');

        // keep only ids that actually belong to this attribute (never delete across attributes)
        $owned = array_map('intval', $conn->fetchCol(
            $conn->select()->from($tOpt, 'option_id')
                ->where('attribute_id = ?', $attributeId)
                ->where('option_id IN (?)', $optionIds)
        ));
        if (!$owned) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($owned, 2000) as $chunk) {
            $conn->beginTransaction();
            try {
                $conn->delete($tVal, ['option_id IN (?)' => $chunk]);
                $conn->delete($tSw, ['option_id IN (?)' => $chunk]);
                $deleted += (int) $conn->delete($tOpt, ['option_id IN (?)' => $chunk, 'attribute_id = ?' => $attributeId]);
                $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw new LocalizedException(__('Bulk delete failed: %1', $e->getMessage()));
            }
        }
        $this->reinitableConfig->reinit();
        return $deleted;
    }

    /**
     * Delete EVERY option matching the current search + assignment filter (across all pages), e.g.
     * "all unassigned options". Resolves the full matching id set with the same filters getPage()
     * uses, then hands off to deleteMany(). Returns the number removed.
     *
     * @throws LocalizedException
     */
    public function deleteAllMatching(int $attributeId, string $search = '', string $assigned = ''): int
    {
        $attribute = $this->attribute($attributeId);
        $conn = $this->resource->getConnection();
        $tOpt = $this->resource->getTableName('eav_attribute_option');
        $tVal = $this->resource->getTableName('eav_attribute_option_value');
        $source = $this->usageSource($attribute);
        $usedSet = $source['multiselect'] ? $this->multiselectUsedSet($attributeId, $source) : null;

        $sel = $conn->select()->from(['o' => $tOpt], ['o.option_id'])->group('o.option_id');
        $this->applyFilters($sel, $tVal, $search, $assigned, $attributeId, $source, $usedSet);
        $ids = array_map('intval', $conn->fetchCol($sel));
        return $this->deleteMany($attributeId, $ids);
    }

    /**
     * Count how many of the matching options are still assigned to a product — so the UI can warn
     * before a bulk delete touches in-use values. Cheap when a filter is already narrowing the set.
     */
    public function countAssignedInMatch(int $attributeId, string $search = '', string $assigned = ''): int
    {
        // An "unassigned only" set has, by definition, nothing in use.
        if ($assigned === 'no') {
            return 0;
        }
        $attribute = $this->attribute($attributeId);
        $source = $this->usageSource($attribute);
        $usedSet = $source['multiselect'] ? $this->multiselectUsedSet($attributeId, $source) : null;
        $conn = $this->resource->getConnection();
        $tOpt = $this->resource->getTableName('eav_attribute_option');
        $tVal = $this->resource->getTableName('eav_attribute_option_value');
        // options matching the (search) filter that are ALSO assigned to a product
        $sel = $conn->select()->from(['o' => $tOpt], ['n' => 'COUNT(DISTINCT o.option_id)']);
        $this->applyFilters($sel, $tVal, $search, 'yes', $attributeId, $source, $usedSet);
        return (int) $conn->fetchOne($sel);
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

    /**
     * Apply the shared attribute + search + product-assignment filters to an option Select (alias
     * 'o' on eav_attribute_option). One place so getPage()/deleteAllMatching()/countAssignedInMatch()
     * stay in lock-step.
     *
     * @param array{table:string,multiselect:bool} $source
     * @param int[]|null                            $usedSet parsed used option-ids (multiselect only)
     */
    private function applyFilters($select, string $tVal, string $search, string $assigned, int $attributeId, array $source, ?array $usedSet): void
    {
        $conn = $this->resource->getConnection();
        $select->where('o.attribute_id = ?', $attributeId);

        // Search by NAME (admin label LIKE) or ID (exact option_id when numeric).
        if ($search !== '') {
            $select->joinLeft(['v' => $tVal], 'v.option_id = o.option_id AND v.store_id = 0', []);
            $cond = $conn->quoteInto('v.value LIKE ?', '%' . $search . '%');
            if (ctype_digit($search)) {
                $cond = '(' . $cond . ' OR ' . $conn->quoteInto('o.option_id = ?', (int) $search) . ')';
            }
            $select->where($cond);
        }

        if ($assigned !== 'yes' && $assigned !== 'no') {
            return; // "any" — no assignment constraint
        }

        if ($source['multiselect']) {
            // used ids were parsed from the comma-packed value column
            $ids = $usedSet ?? [];
            if ($assigned === 'yes') {
                $ids ? $select->where('o.option_id IN (?)', $ids) : $select->where('1 = 0');
            } elseif ($ids) {
                $select->where('o.option_id NOT IN (?)', $ids);
            }
            return;
        }

        // single-value select/swatch: the backend value column holds the option id directly, so a
        // (NOT) IN subquery answers "assigned?" without materialising the set in PHP.
        $sub = (string) $conn->select()->from($source['table'], ['value'])
            ->where('attribute_id = ?', $attributeId)
            ->where('value IS NOT NULL');
        $select->where('o.option_id ' . ($assigned === 'yes' ? 'IN' : 'NOT IN') . ' (' . $sub . ')');
    }

    /**
     * Where and how a product records this attribute's value, so we can tell which options are in
     * use. `table` is the EAV backend table (int/varchar/text — resolved from the attribute, so it
     * is correct whatever the backend_type). `multiselect` marks the comma-packed value layout.
     *
     * @return array{table:string,multiselect:bool}
     */
    private function usageSource(AbstractAttribute $attribute): array
    {
        return [
            'table' => $this->resource->getTableName((string) $attribute->getBackendTable()),
            'multiselect' => $attribute->getFrontendInput() === 'multiselect',
        ];
    }

    /**
     * Distinct option-ids used by any product for a multiselect attribute, parsed out of the
     * comma-packed value column (e.g. "74,80"). Computed once per request and reused.
     *
     * @param array{table:string,multiselect:bool} $source
     * @return int[]
     */
    private function multiselectUsedSet(int $attributeId, array $source): array
    {
        $conn = $this->resource->getConnection();
        $rows = $conn->fetchCol(
            $conn->select()->distinct()->from($source['table'], ['value'])
                ->where('attribute_id = ?', $attributeId)
                ->where('value IS NOT NULL')
                ->where("value <> ''")
        );
        $set = [];
        foreach ($rows as $csv) {
            foreach (explode(',', (string) $csv) as $part) {
                $part = trim($part);
                if ($part !== '' && ctype_digit($part)) {
                    $set[(int) $part] = true;
                }
            }
        }
        return array_keys($set);
    }

    /**
     * Which of the given (already-bounded to a page) option ids are assigned to a product.
     *
     * @param array{table:string,multiselect:bool} $source
     * @param int[]                                 $optionIds
     * @param int[]|null                            $usedSet parsed used ids (multiselect only)
     * @return array<int,bool> option_id => assigned
     */
    private function assignedFlags(int $attributeId, array $source, array $optionIds, ?array $usedSet): array
    {
        $flags = array_fill_keys($optionIds, false);
        if (!$optionIds) {
            return $flags;
        }
        if ($source['multiselect']) {
            $used = array_fill_keys($usedSet ?? [], true);
            foreach ($optionIds as $oid) {
                if (isset($used[$oid])) {
                    $flags[$oid] = true;
                }
            }
            return $flags;
        }
        $conn = $this->resource->getConnection();
        $used = $conn->fetchCol(
            $conn->select()->distinct()->from($source['table'], ['value'])
                ->where('attribute_id = ?', $attributeId)
                ->where('value IN (?)', $optionIds)
        );
        foreach ($used as $v) {
            if (isset($flags[(int) $v])) {
                $flags[(int) $v] = true;
            }
        }
        return $flags;
    }

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
