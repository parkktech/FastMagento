<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\ResourceModel\Fulltext;

use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection as CoreFulltextCollection;

/**
 * Legacy concrete collection retained for existing integrations.
 *
 * New installations use CollectionEntityHydration on the resolved fulltext collection. Magento
 * intercepts public _loadEntities(), so another module's subclass and virtual-type arguments can
 * remain intact while the SAME ListingHydrator skips entity/EAV SQL. No replacement preference
 * is required. This wrapper remains available to integrations that already extend it.
 *
 * It adds no behaviour of its own: the plugin intercepts _loadEntities() on this subclass as it
 * does on any other, so the earlier lazily-resolved override was redundant.
 */
class Collection extends CoreFulltextCollection
{
}
