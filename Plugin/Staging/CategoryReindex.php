<?php
declare(strict_types=1);
namespace ParkkTech\FastMagento\Plugin\Staging;

use Magento\Framework\Indexer\IndexerRegistry;

/** Commerce applies time transitions without saving the EAV rows again. */
class CategoryReindex
{
    public function __construct(private readonly IndexerRegistry $indexers) {}
    public function afterExecute($subject, $result, array $entityIds)
    {
        if ($entityIds) { $this->indexers->get('fastmagento_category')->reindexList($entityIds); }
        return $result;
    }
}
