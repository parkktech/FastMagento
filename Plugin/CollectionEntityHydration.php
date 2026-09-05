<?php
declare(strict_types=1);
namespace ParkkTech\FastMagento\Plugin;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection;
use Magento\Framework\App\{Area,State};
use ParkkTech\FastMagento\Model\Plp\ListingHydrator;
/** Run the existing OS hydrator before SQL, without replacing a third-party collection class. */
class CollectionEntityHydration
{
    public function __construct(private readonly State $state, private readonly ListingHydrator $hydrator) {}
    public function around_loadEntities(Collection $subject, callable $proceed, $printQuery = false, $logQuery = false)
    {
        try { $enabled = $this->state->getAreaCode() === Area::AREA_FRONTEND && $this->hydrator->isEnabled(); }
        catch (\Throwable $e) { $enabled = false; }
        if ($enabled && $this->hydrator->hydrate($subject)) { return $subject; }
        return $proceed($printQuery, $logQuery);
    }
}
