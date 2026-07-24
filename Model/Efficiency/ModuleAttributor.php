<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Efficiency;

use Magento\Framework\Module\ModuleListInterface;

/**
 * Maps a PHP class (from a db.log stack frame) to the Magento module that owns it, and
 * decides whether that module is "third party" — i.e. neither Magento core nor this module.
 *
 * A module named "Vendor_Module" owns the PSR-4 namespace "Vendor\Module\...", so the owner
 * of a class is simply the enabled module whose namespace prefix is the longest match. That
 * is how a query fired deep inside {@see \Magento\Catalog\Model\ResourceModel\Product} gets
 * blamed on Webkul when a Webkul observer/plugin is the frame that triggered the load.
 */
class ModuleAttributor
{
    /** Vendors that are never "third party" for the purposes of this monitor. */
    private const FIRST_PARTY_VENDORS = ['Magento', 'ParkkTech'];

    /** @var array<string, array{module:string, vendor:string, title:string}>|null namespace-prefix => module */
    private ?array $prefixMap = null;

    public function __construct(
        private readonly ModuleListInterface $moduleList
    ) {
    }

    /**
     * Resolve the third-party module that a stack of frames should be blamed for, if any.
     * Frames are ordered innermost-first, so we return the *deepest* third-party frame —
     * the actual extension code sitting closest to the query it triggered.
     *
     * @param array<int, array{class:string, method:string}> $frames
     * @return array{module:string, vendor:string, title:string, class:string, method:string}|null
     */
    public function attribute(array $frames): ?array
    {
        foreach ($frames as $frame) {
            $module = $this->resolveModule($frame['class']);
            if ($module !== null && !$this->isFirstParty($module['vendor'])) {
                return $module + ['class' => $frame['class'], 'method' => $frame['method']];
            }
        }
        return null;
    }

    /**
     * @return array{module:string, vendor:string, title:string}|null
     */
    public function resolveModule(string $class): ?array
    {
        $class = ltrim($class, '\\');
        $best = null;
        $bestLen = 0;
        foreach ($this->getPrefixMap() as $prefix => $module) {
            $len = strlen($prefix);
            if ($len > $bestLen && str_starts_with($class, $prefix)) {
                $best = $module;
                $bestLen = $len;
            }
        }
        return $best;
    }

    public function isFirstParty(string $vendor): bool
    {
        return in_array($vendor, self::FIRST_PARTY_VENDORS, true);
    }

    /**
     * @return array<string, array{module:string, vendor:string, title:string}>
     */
    private function getPrefixMap(): array
    {
        if ($this->prefixMap !== null) {
            return $this->prefixMap;
        }

        $map = [];
        foreach ($this->moduleList->getNames() as $moduleName) {
            // "Vendor_Module" => namespace prefix "Vendor\Module\"
            $parts = explode('_', $moduleName, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$vendor, $module] = $parts;
            $prefix = $vendor . '\\' . $module . '\\';
            $map[$prefix] = [
                'module' => $moduleName,
                'vendor' => $vendor,
                'title'  => $this->humanize($moduleName),
            ];
        }

        $this->prefixMap = $map;
        return $map;
    }

    /** "Webkul_Marketplace" => "Webkul Marketplace" for display. */
    private function humanize(string $moduleName): string
    {
        return str_replace('_', ' ', $moduleName);
    }
}
