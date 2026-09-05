<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\OpenSearch;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;

/**
 * Builds a Category model from its indexed document — every attribute the category indexer
 * projects (structure, menu flags, url paths, request path and the full storefront attribute
 * set: description, image, design and layout settings, sort options, meta fields) — so the
 * places that only READ a category on the storefront (the category controller, breadcrumbs,
 * design resolution, the layer) get one without touching catalog_category_entity.
 *
 * The model is marked as loaded and unchanged: nothing downstream should save it, and a save
 * of a model built here would be refused by Magento's own dirty-tracking anyway.
 */
class CategoryModelBuilder
{
    /** Per-request: same document, same object (as CategoryRepository would guarantee). */
    private array $built = [];

    public function __construct(
        private readonly CategoryDataProvider $categoryData,
        private readonly CategoryFactory $categoryFactory
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->categoryData->isAvailable();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function doc(int $categoryId): ?array
    {
        return $this->categoryData->getById($categoryId);
    }

    public function build(int $categoryId, int $storeId): ?Category
    {
        if (isset($this->built[$storeId][$categoryId])) {
            return $this->built[$storeId][$categoryId];
        }
        $doc = $this->categoryData->getById($categoryId);
        if ($doc === null || (int) ($doc['store_id'] ?? 0) !== $storeId) {
            return null;
        }
        $data = $doc;
        $attrs = (array) ($data['fm_attrs'] ?? []);
        unset($data['fm_attrs']);
        foreach ($attrs as $code => $value) {
            $data[$code] = $value;
        }
        $data['entity_id'] = (string)$categoryId;
        $data['path_ids'] = array_map('intval', (array) ($data['path_ids'] ?? []));

        /** @var Category $category */
        $category = $this->categoryFactory->create();
        $category->setData($data);
        $category->setStoreId($storeId);
        $category->setOrigData();
        $category->setHasDataChanges(false);
        $category->isObjectNew(false);
        return $this->built[$storeId][$categoryId] = $category;
    }
}
