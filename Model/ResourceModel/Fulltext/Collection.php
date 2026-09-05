<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\ResourceModel\Fulltext;

use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection as CoreFulltextCollection;
use Magento\Framework\App\Area;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State;
use ParkkTech\FastMagento\Model\Plp\ListingHydrator;

/**
 * Legacy concrete collection retained for existing integrations.
 *
 * New installations use CollectionEntityHydration on the resolved fulltext collection. Magento
 * intercepts public _loadEntities(), so another module's subclass and virtual-type arguments can
 * remain intact while the SAME ListingHydrator skips entity/EAV SQL. No replacement preference
 * is required. This wrapper remains available to integrations that already extend it.
 */
class Collection extends CoreFulltextCollection
{
    private ?ListingHydrator $fastMagentoHydrator = null;
    private ?State $fastMagentoAppState = null;

    /**
     * Populate the collection for the current page.
     *
     * By this point the engine has already supplied the ids, their order, the page limit and the
     * layered-navigation facet counts — everything except the product data itself. When the whole
     * page can be served from the index we fill it here and skip the EAV pass entirely; otherwise
     * we defer to core, which is also what happens on any miss, any error, and in the admin.
     *
     * @param bool $printQuery
     * @param bool $logQuery
     * @return $this
     */
    public function _loadEntities($printQuery = false, $logQuery = false)
    {
        if ($this->fastMagentoCanServe() && $this->fastMagentoGetHydrator()->hydrate($this)) {
            return $this;
        }

        return parent::_loadEntities($printQuery, $logQuery);
    }

    private function fastMagentoCanServe(): bool
    {
        try {
            if ($this->fastMagentoGetAppState()->getAreaCode() !== Area::AREA_FRONTEND) {
                return false;
            }
        } catch (\Throwable $e) {
            // Area not set yet — never take over.
            return false;
        }

        return $this->fastMagentoGetHydrator()->isEnabled();
    }

    /**
     * Resolved lazily rather than through the constructor: the parent signature carries ~35
     * arguments and is extended by other modules, so adding to it is a compatibility hazard for
     * no benefit — these are only needed on the frontend load path.
     */
    private function fastMagentoGetHydrator(): ListingHydrator
    {
        if ($this->fastMagentoHydrator === null) {
            $this->fastMagentoHydrator = ObjectManager::getInstance()->get(ListingHydrator::class);
        }
        return $this->fastMagentoHydrator;
    }

    private function fastMagentoGetAppState(): State
    {
        if ($this->fastMagentoAppState === null) {
            $this->fastMagentoAppState = ObjectManager::getInstance()->get(State::class);
        }
        return $this->fastMagentoAppState;
    }
}
