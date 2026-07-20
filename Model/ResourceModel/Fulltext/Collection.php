<?php

namespace ParkkTech\FastMagento\Model\ResourceModel\Fulltext;

use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection implements SearchResultInterface
{
    protected $aggregations;
    protected $searchCriteria;
    protected $totalCount;

    protected function _construct()
    {
        $this->_init(
            'ParkkTech\FastMagento\Model\Product',
            'ParkkTech\FastMagento\Model\ResourceModel\Product'
        );
    }

    public function setItems(?array $items = null)
    {
        return $this->_setData('items', $items);
    }

    public function getItems()
    {
        return $this->getData('items');
    }

    public function getAggregations()
    {
        return $this->aggregations;
    }

    public function setAggregations($aggregations)
    {
        $this->aggregations = $aggregations;
        return $this;
    }

    public function getSearchCriteria()
    {
        return $this->searchCriteria;
    }

    public function setSearchCriteria(SearchCriteriaInterface $searchCriteria)
    {
        $this->searchCriteria = $searchCriteria;
        return $this;
    }

    public function getTotalCount()
    {
        return $this->totalCount;
    }

    public function setTotalCount($totalCount)
    {
        $this->totalCount = $totalCount;
        return $this;
    }
}
