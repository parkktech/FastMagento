<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Block\Adminhtml\Efficiency;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Lock\LockManagerInterface;
use ParkkTech\FastMagento\Model\Efficiency\Profiler;
use ParkkTech\FastMagento\Model\Efficiency\ReportStorage;

/**
 * View-model for the Extension Efficiency dashboard. Loads the last scan report and exposes
 * pre-computed presentation data (severity, bar geometry, top offenders) so the template stays
 * declarative. Read-only: the profiling itself happens in the CLI/cron scan.
 */
class Dashboard extends Template
{
    protected $_template = 'ParkkTech_FastMagento::efficiency/dashboard.phtml';

    private ?array $report = null;
    private bool $loaded = false;

    /** A report older than this many days is flagged stale. */
    private const STALE_AFTER_DAYS = 7;

    public function __construct(
        Context $context,
        private readonly ReportStorage $reportStorage,
        private readonly LockManagerInterface $lockManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getReport(): ?array
    {
        if (!$this->loaded) {
            $this->report = $this->reportStorage->load();
            $this->loaded = true;
        }
        return $this->report;
    }

    public function hasReport(): bool
    {
        return $this->getReport() !== null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getModules(): array
    {
        return $this->getReport()['modules'] ?? [];
    }

    /** Developer-facing N+1 hotspots from the full page renders. @return array<int, array<string, mixed>> */
    public function getFindings(): array
    {
        return $this->getReport()['findings'] ?? [];
    }

    /** Short class name (drops namespace) for compact display. */
    public function shortClass(string $class): string
    {
        $parts = explode('\\', $class);
        return array_pop($parts) ?: $class;
    }

    /** @return array<int, array<string, mixed>> */
    public function getScenarios(): array
    {
        return $this->getReport()['scenarios'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function getExtensionImpact(): array
    {
        return $this->getReport()['extension_impact'] ?? [];
    }

    /** The hot path where extensions add the largest share of queries — for the summary tile. */
    public function getHeaviestPath(): ?array
    {
        $heaviest = null;
        foreach ($this->getExtensionImpact() as $impact) {
            if ($heaviest === null || (float) $impact['tax'] > (float) $heaviest['tax']) {
                $heaviest = $impact;
            }
        }
        return $heaviest;
    }

    /** @return array<string, mixed> */
    public function getTotals(): array
    {
        return $this->getReport()['totals'] ?? ['third_party_modules' => 0, 'third_party_queries' => 0];
    }

    public function getGeneratedAt(): string
    {
        return (string) ($this->getReport()['generated_at'] ?? '');
    }

    public function getSampleSize(): int
    {
        return (int) ($this->getReport()['sample_size'] ?? 0);
    }

    /** The single worst-offending module, for the summary tile. */
    public function getWorstModule(): ?array
    {
        return $this->getModules()[0] ?? null;
    }

    /** Largest worst_per_op across modules — scales the ranking bars. */
    public function getMaxPerOp(): float
    {
        $max = 0.0;
        foreach ($this->getModules() as $module) {
            $max = max($max, (float) ($module['worst_per_op'] ?? 0));
        }
        return $max > 0 ? $max : 1.0;
    }

    public function barWidthPct(float $value, float $max): float
    {
        if ($max <= 0) {
            return 0.0;
        }
        return round(min(100.0, ($value / $max) * 100), 1);
    }

    public function areaLabel(string $area): string
    {
        return match ($area) {
            'pdp'    => 'PDP',
            'plp'    => 'PLP',
            'search' => 'Search',
            default  => ucfirst($area),
        };
    }

    /** The operation each area's per-op figure is measured against. */
    public function areaUnit(string $area): string
    {
        return match ($area) {
            'pdp'    => (string) __('per product load'),
            'plp'    => (string) __('per category page'),
            'search' => (string) __('per search'),
            default  => (string) __('per operation'),
        };
    }

    public function severityLabel(string $severity): string
    {
        return match ($severity) {
            'critical' => (string) __('High impact'),
            'warn'     => (string) __('Moderate'),
            default    => (string) __('Low impact'),
        };
    }

    /** True while a scan holds the profiler lock — the page auto-refreshes until it clears. */
    public function isScanRunning(): bool
    {
        return $this->lockManager->isLocked(Profiler::LOCK_NAME);
    }

    /** The redirect right after clicking "Run scan" carries started=1, before the background run grabs the lock. */
    public function justStarted(): bool
    {
        return (bool) $this->getRequest()->getParam('started');
    }

    /** Whole page should present as "scan in progress" (and auto-refresh) when either is true. */
    public function isInProgress(): bool
    {
        return $this->isScanRunning() || $this->justStarted();
    }

    /** Whole days since the last scan, or null if there is no report. */
    public function getReportAgeDays(): ?int
    {
        $generatedAt = $this->getGeneratedAt();
        if ($generatedAt === '') {
            return null;
        }
        try {
            $then = new \DateTimeImmutable($generatedAt);
            $now = new \DateTimeImmutable();
            return (int) $now->diff($then)->days;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function isStale(): bool
    {
        $age = $this->getReportAgeDays();
        return $age !== null && $age >= self::STALE_AFTER_DAYS;
    }

    public function getScanUrl(): string
    {
        return $this->getUrl('fastmagento/efficiency/scan');
    }

    /** Clean monitor URL (no started flag) — auto-refresh targets this so it stops once the scan ends. */
    public function getIndexUrl(): string
    {
        return $this->getUrl('fastmagento/efficiency/index');
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'fastmagento']);
    }

    /** Short class name for display (drops the namespace but keeps the method). */
    public function shortSignature(string $signature): string
    {
        [$class, $method] = array_pad(explode('::', $signature, 2), 2, '');
        $parts = explode('\\', $class);
        $short = array_pop($parts) ?: $class;
        return $method !== '' ? $short . '::' . $method : $short;
    }
}
