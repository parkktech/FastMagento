<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Model\Category;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\Data\CollectionFactory as DataCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Model\OpenSearch\CategoryDataProvider;
use ParkkTech\FastMagento\Model\OpenSearch\CategoryModelBuilder;

/**
 * Memoise the two child lookups Category makes per request.
 *
 * getChildrenCategories()
 * -----------------------
 *
 * It delegates straight to the resource model, which builds and loads a brand new collection every
 * call — there is no caching on either side. The layered-navigation category filter calls it twice
 * per listing render on the same category: once in apply(), to collect child ids for the
 * `category_ids_to_aggregate` filter, and again in _getItemsData(), to turn the OpenSearch facet
 * counts into filter items. Two identical fetches, each a `getChildren` id query plus a collection
 * load.
 *
 * Keyed by object, not by id: two model instances for the same category can legitimately carry
 * different store scopes, and only the instance we were handed is safe to answer for.
 */
class CategoryChildrenPlugin
{
    private const XML_PATH_SERVE_TREE = 'fastmagento/serving/serve_category_tree';

    /** @var array<int, mixed> spl_object_id($category) => children collection */
    private array $children = [];

    /** @var array<int, bool> spl_object_id($category) => hasChildren() */
    private array $hasChildren = [];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CategoryDataProvider $categoryData,
        private readonly CategoryModelBuilder $modelBuilder,
        private readonly DataCollectionFactory $dataCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Direct active children of a category, from the indexed tree, or null when we cannot answer.
     *
     * @return int[]|null
     */
    private function indexedChildIds(Category $category): ?array
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_SERVE_TREE, ScopeInterface::SCOPE_STORE)) {
            return null;
        }

        try {
            if (!$this->categoryData->isAvailable()) {
                return null;
            }
            $id = (int) $category->getId();
            if (!$id || $this->categoryData->getById($id) === null) {
                return null;
            }

            $ids = [];
            foreach ($this->categoryData->getAll() as $cid => $doc) {
                if ((int) ($doc['parent_id'] ?? 0) === $id && !empty($doc['is_active'])) {
                    $ids[] = (int) $cid;
                }
            }
            sort($ids);

            return $ids;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Count of ACTIVE DESCENDANTS (not just direct children), matching what the resource model's
     * getChildrenAmount() counts: `path LIKE '<path>/%'` with the is_active check.
     *
     * @return int|null
     */
    private function indexedDescendantCount(Category $category): ?int
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_SERVE_TREE, ScopeInterface::SCOPE_STORE)) {
            return null;
        }

        try {
            if (!$this->categoryData->isAvailable()) {
                return null;
            }
            $id = (int) $category->getId();
            $doc = $id ? $this->categoryData->getById($id) : null;
            if ($doc === null || ($doc['path'] ?? '') === '') {
                return null;
            }

            $prefix = $doc['path'] . '/';
            $count = 0;
            foreach ($this->categoryData->getAll() as $cid => $d) {
                if ((int) $cid === $id) {
                    continue;
                }
                if (strpos((string) ($d['path'] ?? ''), $prefix) === 0 && !empty($d['is_active'])) {
                    $count++;
                }
            }

            return $count;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Direct active child ids, as the comma-separated string core returns.
     *
     * Substituted ONLY for the default argument set. getChildren() also accepts $recursive,
     * $isActive and $sortByPosition; the current resource implementation happens to ignore the
     * last two, but relying on that would tie this plugin to a core implementation detail, so any
     * non-default call goes native.
     *
     * ON ORDERING: the native query carries no ORDER BY at all, so its sequence is whatever the
     * join happens to emit -- measured across 40 categories it matched entity_id order in 39 and
     * differed in one. Nothing depends on it: getChildrenCategories(), the storefront caller,
     * feeds the result straight into addIdFilter() and then re-sorts by position, so the value is
     * consumed as a SET. Returning entity_id order is therefore equivalent and deterministic,
     * which the database's answer was not.
     *
     * @param bool $recursive
     * @param bool $isActive
     * @param bool $sortByPosition
     * @return string
     */
    public function aroundGetChildren(
        Category $subject,
        callable $proceed,
        $recursive = false,
        $isActive = true,
        $sortByPosition = false
    ) {
        if ($recursive !== false || $isActive !== true || $sortByPosition !== false) {
            return $proceed($recursive, $isActive, $sortByPosition);
        }

        $ids = $this->indexedChildIds($subject);
        if ($ids === null) {
            return $proceed($recursive, $isActive, $sortByPosition);
        }

        return implode(',', $ids);
    }

    /**
     * The active direct children in position order, as Category models built from the tree —
     * what the layer's category filter, the sidebar and the category viewmodels iterate.
     * Natively one EAV collection load with a url_rewrite join per call site.
     *
     * @return mixed
     */
    public function aroundGetChildrenCategories(Category $subject, callable $proceed)
    {
        $key = spl_object_id($subject);
        if (!array_key_exists($key, $this->children)) {
            $this->children[$key] = $this->indexedChildrenCollection($subject) ?? $proceed();
        }

        return $this->children[$key];
    }

    private function indexedChildrenCollection(Category $subject): ?DataCollection
    {
        try {
            $ids = $this->indexedChildIds($subject);
            if ($ids === null || !$this->modelBuilder->isAvailable()) {
                return null;
            }
            $storeId = (int) $this->storeManager->getStore()->getId();
            $models = [];
            foreach ($ids as $id) {
                $model = $this->modelBuilder->build($id, $storeId);
                if ($model === null) {
                    return null;
                }
                $models[] = $model;
            }
            usort($models, static function ($a, $b) {
                return ((int) $a->getPosition() <=> (int) $b->getPosition()) ?: ((int) $a->getId() <=> (int) $b->getId());
            });
            /** @var DataCollection $collection */
            $collection = $this->dataCollectionFactory->create();
            foreach ($models as $model) {
                $collection->addItem($model);
            }
            return $collection;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * hasChildren() runs `SELECT COUNT(m.entity_id) ... catalog_category_entity` through the
     * resource model on every call, with nothing caching the answer. Catalog\Controller\Category
     * \View::execute() asks twice while deciding how to render the page, which is two identical
     * counts before a single block has rendered.
     *
     * @return bool
     */
    public function aroundHasChildren(Category $subject, callable $proceed): bool
    {
        $key = spl_object_id($subject);
        if (!array_key_exists($key, $this->hasChildren)) {
            $count = $this->indexedDescendantCount($subject);
            $this->hasChildren[$key] = $count !== null ? $count > 0 : (bool) $proceed();
        }

        return $this->hasChildren[$key];
    }
}
