<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Efficiency;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem\DirectoryList;

/**
 * Executes one representative "hot path" operation in-process so the db_logger captures the
 * real SQL (and the third-party plugins/observers that fire on it). Run from the worker CLI
 * command, which bootstraps with db_logger enabled.
 *
 * Each scenario maps to an "impact area" the store owner recognises:
 *   product_load      -> Indexing/PDP: the native full product hydration every getById triggers
 *   fast_product_load -> the FastMagento batched loader over the SAME ids (the "with" measurement)
 *   plp               -> category product grid (layered-nav / listing)
 *   search            -> catalog search results
 *
 * The scenario input (sample ids, search term) is resolved BEFORE the db log is truncated, so
 * only the measured operation's queries are captured — never the sampling itself.
 */
class ScenarioRunner
{
    public const SCENARIOS = [
        'product_load' => ['area' => 'pdp',    'label' => 'Product page / product load'],
        'plp'          => ['area' => 'plp',    'label' => 'Category product grid'],
        'search'       => ['area' => 'search', 'label' => 'Catalog search'],
    ];

    /**
     * @param ProductCollectionFactory $fulltextCollectionFactory Bound in di.xml to the
     *   Magento\CatalogSearch\Model\ResourceModel\Fulltext\CollectionFactory *virtualType* (which
     *   extends the product collection factory). It builds a real fulltext search collection at
     *   runtime; typed as the base class here because a virtualType can't be a constructor hint.
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ProductCollectionFactory $fulltextCollectionFactory,
        private readonly DirectoryList $directoryList
    ) {
    }

    /**
     * @return array{ops:int, area:string, label:string}
     */
    public function run(string $scenario, int $sampleSize): array
    {
        if (!isset(self::SCENARIOS[$scenario])) {
            throw new \InvalidArgumentException("Unknown scenario '$scenario'.");
        }
        $meta = self::SCENARIOS[$scenario];

        // 1. Resolve inputs (these queries must NOT be counted).
        $ids = $scenario === 'product_load' ? $this->sampleProductIds($sampleSize) : [];
        $term = $scenario === 'search' ? $this->sampleSearchTerm() : '';

        // 2. Truncate the db log so only the measured operation below is captured.
        // The db_logger File writer appends per call (mode 'a'), so a fresh truncate here is safe.
        @file_put_contents($this->directoryList->getRoot() . '/var/debug/db.log', '');

        // 3. Run the measured operation.
        $ops = match ($scenario) {
            'product_load' => $this->runProductLoad($ids),
            'plp'          => $this->runPlp($sampleSize),
            'search'       => $this->runSearch($term, $sampleSize),
        };

        return ['ops' => $ops, 'area' => $meta['area'], 'label' => $meta['label']];
    }

    /**
     * @param int[] $ids
     */
    private function runProductLoad(array $ids): int
    {
        $ops = 0;
        foreach ($ids as $id) {
            try {
                // Full hydration on purpose (forceReload defeats the identity map): this is what
                // fires every module's product-load plugins/observers.
                $this->productRepository->getById((int) $id, false, null, true);
                $ops++;
            } catch (\Throwable $e) {
                // A deleted/broken id shouldn't abort the profile run.
            }
        }
        return $ops;
    }

    private function runPlp(int $sampleSize): int
    {
        // One category-page load is one operation — its query cost is what a shopper pays per page.
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('*')
            ->addAttributeToFilter('status', 1)
            ->setPageSize(max(1, min($sampleSize, 48)))
            ->setCurPage(1);
        $collection->load();
        return 1;
    }

    private function runSearch(string $term, int $sampleSize): int
    {
        // One search request is one operation, regardless of how many products it returns.
        $collection = $this->fulltextCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'price', 'small_image'])
            ->addSearchFilter($term)
            ->setPageSize(max(1, min($sampleSize, 48)))
            ->setCurPage(1);
        $collection->load();
        return 1;
    }

    /**
     * Sample product ids spread evenly across the id range, so the profile isn't skewed toward
     * one product type clustered at the low ids. Uses cheap PK point-lookups (uncounted; resolved
     * before the log is truncated).
     *
     * @return int[]
     */
    private function sampleProductIds(int $limit): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalog_product_entity');

        $bounds = $connection->fetchRow(
            $connection->select()->from($table, ['min' => 'MIN(entity_id)', 'max' => 'MAX(entity_id)'])
        );
        $min = (int) ($bounds['min'] ?? 0);
        $max = (int) ($bounds['max'] ?? 0);
        if ($min === 0 && $max === 0) {
            return [];
        }
        if ($max - $min < $limit) {
            // Small catalogue — just take them all in order.
            return array_map('intval', $connection->fetchCol(
                $connection->select()->from($table, ['entity_id'])->order('entity_id ASC')->limit($limit)
            ));
        }

        $step = ($max - $min) / $limit;
        $ids = [];
        for ($i = 0; $i < $limit; $i++) {
            $target = (int) floor($min + $i * $step);
            $id = $connection->fetchOne(
                $connection->select()
                    ->from($table, ['entity_id'])
                    ->where('entity_id >= ?', $target)
                    ->order('entity_id ASC')
                    ->limit(1)
            );
            if ($id !== false && !isset($ids[(int) $id])) {
                $ids[(int) $id] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * A search term that actually matches something: the first word of a real product name.
     * Scoped to the `name` attribute so we don't accidentally pick a url_key or SKU that the
     * fulltext index won't match.
     */
    private function sampleSearchTerm(): string
    {
        $connection = $this->resource->getConnection();
        $varchar = $this->resource->getTableName('catalog_product_entity_varchar');
        $eavAttr = $this->resource->getTableName('eav_attribute');

        $select = $connection->select()
            ->from(['v' => $varchar], ['value'])
            ->join(['a' => $eavAttr], 'a.attribute_id = v.attribute_id', [])
            ->where('a.attribute_code = ?', 'name')
            ->where('v.value IS NOT NULL')
            ->where('v.value != ?', '')
            ->limit(1);
        $value = (string) $connection->fetchOne($select);

        // Pick the first real word (letters only, 4+ chars) so we don't search on a size token
        // like 48" or a stray symbol that the fulltext index won't match.
        if (preg_match('/[A-Za-z]{4,}/', $value, $m)) {
            return $m[0];
        }
        return 'shirt';
    }
}
