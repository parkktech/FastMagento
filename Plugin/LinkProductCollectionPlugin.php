<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Model\ResourceModel\Product\Link\Product\Collection;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;
use ParkkTech\FastMagento\Helper\ShellProductBuilder;
use ParkkTech\FastMagento\Helper\WriteLog;

/**
 * Serve Related / Up-sell / Cross-sell (product-link) collections from OpenSearch.
 *
 * The native link collection applies its parent+link-type filter lazily (in _renderFiltersBefore,
 * inside load()), so the collection's own getAllIds() runs UNFILTERED and returns every product —
 * unusable for a short-circuit. Instead we read the linked ids directly from catalog_product_link
 * (the link graph isn't indexed in OS), in position order, then hydrate each linked product from a
 * single OS mget. Each shell carries a url_data_object built from the indexed request_path, so
 * Product\Url::getUrl() takes the hasUrlDataObject() branch and never calls the url_rewrite finder
 * (the per-grid-item N+1 that first broke this).
 *
 * All-or-nothing: if the link query throws, or ANY visible linked product is missing from OS, it
 * falls back to the native load so a block never silently drops a merchant-linked product.
 */
class LinkProductCollectionPlugin
{
    /** Visibility ids that appear on storefront product grids (everything except "not visible"). */
    private const VISIBLE = [
        \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_CATALOG,
        \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_SEARCH,
        \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH,
    ];
    private const STATUS_ENABLED = 1;

    public function __construct(
        private readonly State $appState,
        private readonly ResourceConnection $resource,
        private readonly OpenSearchPdpFetcher $fetcher,
        private readonly ShellProductBuilder $shellProductBuilder,
        private readonly WriteLog $writeLog
    ) {
    }

    /**
     * @param Collection $subject
     * @param callable $proceed
     * @return Collection
     */
    public function aroundLoad(Collection $subject, callable $proceed, $printQuery = false, $logQuery = false)
    {
        try {
            if ($subject->isLoaded()
                || $this->appState->getAreaCode() !== 'frontend'
                || !$subject->getLinkModel()
                || !$subject->getLinkModel()->getLinkTypeId()
                || !$subject->getProduct()
                || !$subject->getProduct()->getId()
            ) {
                return $proceed($printQuery, $logQuery);
            }

            $ids = $this->getLinkedIds(
                (int) $subject->getProduct()->getId(),
                (int) $subject->getLinkModel()->getLinkTypeId()
            );
            if (!$ids) {
                return $proceed($printQuery, $logQuery);
            }

            $docs = $this->fetcher->fetchByIds($ids);

            $shells = [];
            foreach ($ids as $id) {                       // position order preserved
                $doc = $docs[$id] ?? null;
                if ($doc === null) {
                    return $proceed($printQuery, $logQuery);   // a linked product not in OS → native
                }
                if (!in_array((int) ($doc['visibility'] ?? 0), self::VISIBLE, true)
                    || (int) ($doc['status'] ?? 0) !== self::STATUS_ENABLED) {
                    continue;                              // native block would also hide these
                }
                $shell = $this->shellProductBuilder->buildNoEavProductFromOsDoc($doc);
                if (!empty($doc['request_path'])) {
                    $shell->setData('url_data_object', new DataObject([
                        'url_rewrite' => $doc['request_path'],
                        'store_id' => $shell->getStoreId(),
                    ]));
                }
                $shells[] = $shell;
            }

            foreach ($shells as $shell) {
                $subject->addItem($shell);
            }
            (function () {
                $this->_setIsLoaded(true);
            })->call($subject);

            return $subject;
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] link-collection OS serve failed, using native: ' . $e->getMessage()
            );
            return $proceed($printQuery, $logQuery);
        }
    }

    /**
     * Linked product ids for a parent + link type, in position order, straight from the link
     * graph (catalog_product_link is not indexed in OpenSearch).
     *
     * @return int[]
     */
    private function getLinkedIds(int $parentId, int $linkTypeId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['l' => $this->resource->getTableName('catalog_product_link')], ['linked_product_id'])
            ->joinLeft(
                ['la' => $this->resource->getTableName('catalog_product_link_attribute')],
                'la.link_type_id = l.link_type_id AND la.product_link_attribute_code = ' . $connection->quote('position'),
                []
            )
            ->joinLeft(
                ['ai' => $this->resource->getTableName('catalog_product_link_attribute_int')],
                'ai.product_link_attribute_id = la.product_link_attribute_id AND ai.link_id = l.link_id',
                ['position' => 'ai.value']
            )
            ->where('l.product_id = ?', $parentId)
            ->where('l.link_type_id = ?', $linkTypeId)
            ->order('position ' . \Magento\Framework\DB\Select::SQL_ASC)
            ->order('l.link_id ' . \Magento\Framework\DB\Select::SQL_ASC);

        return array_map('intval', $connection->fetchCol($select));
    }
}
