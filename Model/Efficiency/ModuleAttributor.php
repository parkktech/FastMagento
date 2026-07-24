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

    /**
     * Interception plugins on the request / action / app lifecycle. These wrap the *entire*
     * dispatched request (`$proceed($request)`), so they sit on the stack of virtually every query
     * a page fires — but they own none of it: the queries run inside the core code they wrap, not in
     * the plugin's own body. Blaming a broad wrapper for core's queries is noise (e.g. Webkul's
     * `Context::aroundDispatch`, which only stamps customer_id into the http context, would otherwise
     * "own" every core catalog_category_entity load on a category page). A query whose ONLY
     * third-party frame is one of these is core work — attribute it to nobody. A genuine third-party
     * caller always appears deeper (closer to the query) and is returned first, so real findings
     * (e.g. a mega-menu's own `loadTree`) are unaffected.
     */
    private const WRAPPER_METHODS = [
        'aroundDispatch', 'beforeDispatch', 'afterDispatch',
        'aroundLaunch', 'beforeLaunch', 'afterLaunch',
    ];

    /** @var array<string, array{module:string, vendor:string, title:string}>|null namespace-prefix => module */
    private ?array $prefixMap = null;

    /** @var array<string, array{module:string, vendor:string, title:string}|null> (class::method) => owning module */
    private array $owningModuleCache = [];

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
            if (in_array($frame['method'], self::WRAPPER_METHODS, true)) {
                continue; // request-lifecycle wrapper — see WRAPPER_METHODS
            }
            $module = $this->resolveOwningModule($frame['class'], $frame['method']);
            if ($module !== null && !$this->isFirstParty($module['vendor'])) {
                return $module + ['class' => $frame['class'], 'method' => $frame['method']];
            }
        }
        return null;
    }

    /**
     * Resolve the module that truly OWNS a frame's behaviour: the module of the class that
     * *declares* the method, not the runtime subclass that inherited it. A third-party class that
     * merely subclasses a core model / customer-data source and inherits a method unchanged is not
     * responsible for the queries that core method fires — blaming it produces false positives.
     * (Concretely: Webkul's Cart customer-data rewrite only overrides getRecentItems(), yet it
     * would otherwise "own" core's getSectionData() → checkout-session/totals/quote_address queries
     * simply because it is the instantiated class.) If the method is inherited from core, this
     * returns the core (first-party) module, so the frame is skipped as not-third-party.
     *
     * @return array{module:string, vendor:string, title:string}|null
     */
    public function resolveOwningModule(string $class, string $method): ?array
    {
        $key = $class . '::' . $method;
        if (array_key_exists($key, $this->owningModuleCache)) {
            return $this->owningModuleCache[$key];
        }
        return $this->owningModuleCache[$key] = $this->resolveModule($this->declaringClass($class, $method));
    }

    /**
     * The class that declares $method on $class (walking up to the core ancestor that actually
     * defined it), or $class itself when reflection can't resolve it (magic methods, closures,
     * missing classes) — a safe fall back to the previous runtime-class behaviour.
     */
    private function declaringClass(string $class, string $method): string
    {
        $c = ltrim($class, '\\');
        if ($method === '' || !class_exists($c) || !method_exists($c, $method)) {
            return $c;
        }
        try {
            return (new \ReflectionMethod($c, $method))->getDeclaringClass()->getName();
        } catch (\ReflectionException $e) {
            return $c;
        }
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
