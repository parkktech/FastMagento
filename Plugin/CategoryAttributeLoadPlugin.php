<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Framework\App\State;
use ParkkTech\FastMagento\Model\OpenSearch\CategoryDataProvider;

/**
 * Serve category collection attribute values from OpenSearch instead of the
 * catalog_category_entity_{varchar,int,text} UNION loads.
 *
 * Every storefront category collection — the mega-menu, the core top-nav tree, breadcrumb
 * parents (getParentCategories) and layered-nav children (getChildrenCategories) — runs the
 * native main entity query (filters/sorting stay 100% native and correct) and then a set of
 * per-backend-type UNION queries to fetch the SELECTED attribute values. This plugin fills
 * those selected values from the in-memory OS tree instead.
 *
 * It only skips the native UNION load when it can serve EVERY selected attribute for EVERY
 * loaded item from OpenSearch; on any gap (an attribute we don't index, an item missing from
 * the index, OS down, wrong area) it falls straight through to the native load. Entity
 * selection, filtering and ordering are never touched, so a fallback is always correct — the
 * worst case is the original query, never wrong data.
 */
class CategoryAttributeLoadPlugin
{
    /** Selected EAV attributes we can serve from the category doc (code => doc field). */
    private const SERVABLE = [
        'name' => 'name',
        'url_key' => 'url_key',
        'url_path' => 'url_path',
        'is_active' => 'is_active',
        'include_in_menu' => 'include_in_menu',
        'is_anchor' => 'is_anchor',
        'display_mode' => 'display_mode',
        'all_children' => 'all_children',
    ];

    private ?\ReflectionProperty $selectAttributesProp = null;
    private ?\ReflectionProperty $itemsByIdProp = null;

    public function __construct(
        private readonly State $appState,
        private readonly CategoryDataProvider $categoryData
    ) {
    }

    /**
     * @param Collection $subject
     * @param callable $proceed
     * @return Collection
     */
    public function around_loadAttributes(Collection $subject, callable $proceed, ...$args)
    {
        try {
            if ($this->appState->getAreaCode() !== 'frontend' || !$this->categoryData->isAvailable()) {
                return $proceed(...$args);
            }

            // Read the already-populated item map directly ([entityId => [item, ...]], the
            // shape native _loadAttributes writes to). We are INSIDE load() (_loadEntities
            // ran, _loadAttributes has not) so the collection is not yet flagged loaded —
            // calling getItems()/getData() here would re-enter load().
            $itemsById = $this->readItemsById($subject);
            if (!$itemsById) {
                return $proceed(...$args);
            }

            $selected = $this->readSelectedAttributes($subject);
            if ($selected === null) {
                return $proceed(...$args);
            }

            // Every selected non-static attribute must be one we can serve from OS.
            foreach (array_keys($selected) as $code) {
                if (!isset(self::SERVABLE[$code])) {
                    return $proceed(...$args);
                }
            }

            // Every loaded entity must exist in the OS tree; collect docs first so a single
            // miss cleanly aborts to native without half-populating.
            $docs = [];
            foreach (array_keys($itemsById) as $entityId) {
                $doc = $this->categoryData->getById((int) $entityId);
                if ($doc === null) {
                    return $proceed(...$args);
                }
                $docs[$entityId] = $doc;
            }

            foreach ($itemsById as $entityId => $group) {
                $doc = $docs[$entityId];
                foreach ((array) $group as $item) {
                    foreach ($selected as $code => $attributeId) {
                        $item->setData($code, $doc[self::SERVABLE[$code]] ?? null);
                    }
                }
            }

            // All selected values served from OS — skip the native UNION queries entirely.
            return $subject;
        } catch (\Throwable $e) {
            return $proceed(...$args);
        }
    }

    /**
     * Read the collection's selected non-static EAV attributes ([code => attributeId]).
     * Uses one cached reflection handle on the long-stable EAV property; returns null on
     * any failure so the caller falls back to native.
     *
     * @return array<string, mixed>|null
     */
    private function readSelectedAttributes(Collection $subject): ?array
    {
        try {
            if ($this->selectAttributesProp === null) {
                $prop = new \ReflectionProperty(
                    \Magento\Eav\Model\Entity\Collection\AbstractCollection::class,
                    '_selectAttributes'
                );
                $prop->setAccessible(true);
                $this->selectAttributesProp = $prop;
            }
            $value = $this->selectAttributesProp->getValue($subject);
            if (!is_array($value)) {
                return null;
            }
            // Drop entries with no attribute id (nothing the UNION load would fetch).
            return array_filter($value, static fn ($id) => !empty($id));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Read the collection's already-loaded items keyed by entity id, without triggering a
     * re-entrant load() (which getItems() would do while we are mid-load). Returns null on
     * any failure so the caller falls back to native.
     *
     * @return array<int, \Magento\Framework\DataObject>|null
     */
    private function readItemsById(Collection $subject): ?array
    {
        try {
            if ($this->itemsByIdProp === null) {
                $prop = new \ReflectionProperty(
                    \Magento\Eav\Model\Entity\Collection\AbstractCollection::class,
                    '_itemsById'
                );
                $prop->setAccessible(true);
                $this->itemsByIdProp = $prop;
            }
            $value = $this->itemsByIdProp->getValue($subject);
            return is_array($value) ? $value : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
