<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Mview;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Mview\View\Subscription;
use Magento\Framework\Mview\ViewInterface;
use ParkkTech\FastMagento\Model\Db\EntityLink;

/** Resolve Commerce relationship parent row IDs before writing the FastMagento changelog. */
class RelationshipEntityId
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EntityLink $entityLink
    ) {
    }

    public function afterGetEntityColumn(
        Subscription $subject,
        string $result,
        string $prefix,
        ViewInterface $view
    ): string {
        if ($view->getId() !== 'fastmagento_product' || !$this->entityLink->isProductStaged()) {
            return $result;
        }

        $columns = [
            $this->resource->getTableName('catalog_product_link') => 'product_id',
            $this->resource->getTableName('catalog_product_bundle_selection') => 'parent_product_id',
        ];
        $column = $columns[$subject->getTableName()] ?? null;
        if ($column === null) {
            return $result;
        }

        $connection = $this->resource->getConnection();
        // Do not use a Magento Select here: this is trigger DDL, not a query for the current
        // request's staging version. The referenced row uniquely identifies its public entity.
        return sprintf(
            '(SELECT entity_id FROM %s WHERE row_id = %s%s)',
            $connection->quoteIdentifier($this->resource->getTableName('catalog_product_entity')),
            $prefix,
            $connection->quoteIdentifier($column)
        );
    }
}
