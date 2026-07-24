<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Efficiency;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Shell;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates an extension-efficiency scan end to end and produces the report the admin
 * dashboard renders.
 *
 * Why subprocesses: db_logger is a deployment-config switch read once at bootstrap, so the
 * process that flips it on can't log itself. For each scenario we therefore (1) truncate the
 * db log, (2) run the scenario in a fresh `bin/magento` worker that bootstraps with logging on,
 * (3) parse the captured SQL, attributing every query to the third-party module whose code
 * triggered it. db_logger is always restored to its prior state in a finally block.
 */
class Profiler
{
    /** Hot-path scenarios; every query is attributed to the third-party module that fired it. */
    private const SCENARIOS = ['product_load', 'plp', 'search'];

    /** Severity thresholds on the worst per-operation third-party query count for a module. */
    private const SEVERITY_CRITICAL = 5.0;
    private const SEVERITY_WARN = 1.0;

    private const AREA_LABELS = [
        'pdp'    => 'PDP / product load',
        'plp'    => 'Category / PLP',
        'search' => 'Search',
    ];

    /** Named lock preventing two scans (admin button + cron) from racing on the db_logger switch. */
    public const LOCK_NAME = 'fastmagento_efficiency_scan';

    /** The exact db_logger config this profiler installs; used to recognise a leftover from a crashed run. */
    private const PROFILING_DB_LOGGER = [
        'output'               => 'file',
        'log_everything'       => 1,
        'query_time_threshold' => '0',
        'include_stacktrace'   => 1,
    ];

    private readonly string $root;

    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly DbLogParser $logParser,
        private readonly ModuleAttributor $attributor,
        private readonly ReportStorage $reportStorage,
        private readonly Json $json,
        private readonly Shell $shell,
        private readonly LockManagerInterface $lockManager,
        private readonly PageProfiler $pageProfiler,
        private readonly LoggerInterface $logger
    ) {
        $this->root = $this->directoryList->getRoot();
    }

    /**
     * Run the full scan and persist the report.
     *
     * @param callable(string):void|null $progress optional line-level progress callback
     */
    public function run(int $sampleSize = 50, ?callable $progress = null): array
    {
        $progress ??= static fn (string $m) => null;

        // Serialize scans: the admin button and cron can both fire, and two concurrent runs would
        // race on the db_logger switch and could leave it enabled store-wide.
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            throw new LocalizedException(__('An efficiency scan is already running. Try again once it finishes.'));
        }

        $prior = $this->readDbLoggerConfig();
        $weEnabledLogging = ($prior === null); // if logging was already on, it's the store owner's — leave it be
        $findings = [];
        try {
            $this->setDbLoggerConfig(true);
            $scenarios = [];
            foreach (self::SCENARIOS as $scenario) {
                $progress("Profiling: $scenario …");
                $scenarios[$scenario] = $this->runScenario($scenario, $sampleSize);
            }
            // Full HTTP page renders — catches block-render N+1 loops the in-process scenarios miss.
            $progress('Profiling: full page renders …');
            $findings = $this->pageProfiler->capture();
        } finally {
            $this->restoreDbLoggerConfig($prior);
            // db.log is written with log_everything + full stacktraces, so it grows fast. Once logging
            // is back off, empty the file we filled so it never lingers or grows between scans.
            if ($weEnabledLogging) {
                @file_put_contents($this->dbLogPath(), '');
            }
            $this->lockManager->unlock(self::LOCK_NAME);
        }

        $report = $this->buildReport($scenarios, $sampleSize);
        $report['findings'] = $findings;
        $this->reportStorage->save($report);
        return $report;
    }

    /**
     * @return array{ops:int, area:string, label:string, queries:array}
     */
    private function runScenario(string $scenario, int $sampleSize): array
    {
        $logPath = $this->dbLogPath();
        @file_put_contents($logPath, '');

        try {
            $raw = $this->shell->execute(
                '%s %s fastmagento:efficiency:worker %s %s',
                [PHP_BINARY, $this->root . '/bin/magento', $scenario, (string) $sampleSize]
            );
        } catch (\Throwable $e) {
            // A single scenario failing (e.g. empty catalogue for search) must not abort the whole scan.
            $this->logger->warning("FastMagento efficiency scenario '$scenario' failed: " . $e->getMessage());
            return ['ops' => 0, 'area' => ScenarioRunner::SCENARIOS[$scenario]['area'] ?? 'unknown', 'label' => $scenario, 'queries' => []];
        }

        $meta = $this->decodeWorkerOutput($raw);
        $queries = $this->logParser->parse($logPath);

        return [
            'ops'     => $meta['ops'],
            'area'    => $meta['area'],
            'label'   => $meta['label'],
            'queries' => $queries,
        ];
    }

    private function buildReport(array $scenarios, int $sampleSize): array
    {
        // module => aggregated third-party cost across the native scenarios
        $modules = [];
        $scenarioSummary = [];

        foreach (self::SCENARIOS as $scenario) {
            $data = $scenarios[$scenario];
            $area = $data['area'];
            $ops = max(1, $data['ops']);
            $thirdPartyCount = 0;

            foreach ($data['queries'] as $query) {
                $hit = $this->attributor->attribute($query['frames']);
                if ($hit === null) {
                    continue;
                }
                $thirdPartyCount++;
                $key = $hit['module'];
                if (!isset($modules[$key])) {
                    $modules[$key] = [
                        'module'    => $hit['module'],
                        'vendor'    => $hit['vendor'],
                        'title'     => $hit['title'],
                        'total'     => 0,
                        'areas'     => [],
                        'per_op'    => [],
                        'tables'    => [],
                        'offenders' => [],
                    ];
                }
                $modules[$key]['total']++;
                $modules[$key]['areas'][$area] = ($modules[$key]['areas'][$area] ?? 0) + 1;
                if ($query['table'] !== null) {
                    $modules[$key]['tables'][$query['table']] = ($modules[$key]['tables'][$query['table']] ?? 0) + 1;
                }
                $offKey = $hit['class'] . '::' . $hit['method'];
                $modules[$key]['offenders'][$offKey] = ($modules[$key]['offenders'][$offKey] ?? 0) + 1;
            }

            $scenarioSummary[$area] = [
                'area'          => $area,
                'label'         => self::AREA_LABELS[$area] ?? $data['label'],
                'ops'           => $data['ops'],
                'total_queries' => count($data['queries']),
                'third_party'   => $thirdPartyCount,
                'per_op'        => round(count($data['queries']) / $ops, 1),
                'third_party_per_op' => round($thirdPartyCount / $ops, 1),
            ];
        }

        $modules = $this->finalizeModules($modules, $scenarios);
        usort($modules, static fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'generated_at'     => $this->now(),
            'sample_size'      => $sampleSize,
            'scenarios'        => array_values($scenarioSummary),
            'modules'          => $modules,
            'extension_impact' => $this->buildExtensionImpact($scenarioSummary),
            'totals'           => [
                'third_party_modules' => count($modules),
                'third_party_queries' => array_sum(array_map(static fn ($m) => $m['total'], $modules)),
            ],
        ];
    }

    /**
     * The honest with-vs-without chart: for each hot path, the query cost as it runs today (with
     * all extensions) versus the same path with the third-party queries removed (core Magento
     * only). The gap is exactly the tax the installed extensions add — which is what a store owner
     * can act on. It is measured from the same run, so nothing has to be toggled or estimated.
     *
     * @param array<string, array<string, mixed>> $scenarioSummary
     */
    private function buildExtensionImpact(array $scenarioSummary): array
    {
        $impact = [];
        foreach ($scenarioSummary as $area => $s) {
            $with = (float) $s['per_op'];
            $tax = (float) $s['third_party_per_op'];
            $without = round(max(0.0, $with - $tax), 1);
            $impact[] = [
                'area'        => $area,
                'label'       => $s['label'],
                'with_ext'    => $with,
                'without_ext' => $without,
                'tax'         => round($tax, 1),
                'pct'         => $with > 0 ? (int) round(($tax / $with) * 100) : 0,
            ];
        }
        return $impact;
    }

    /**
     * Compute per-op rates, severity, and trim offender/table lists to the top few.
     */
    private function finalizeModules(array $modules, array $scenarios): array
    {
        $opsByArea = [];
        foreach (self::SCENARIOS as $scenario) {
            $opsByArea[$scenarios[$scenario]['area']] = max(1, $scenarios[$scenario]['ops']);
        }

        foreach ($modules as &$module) {
            $worst = 0.0;
            foreach ($module['areas'] as $area => $count) {
                $perOp = round($count / ($opsByArea[$area] ?? 1), 1);
                $module['per_op'][$area] = $perOp;
                $worst = max($worst, $perOp);
            }
            $module['worst_per_op'] = $worst;
            $module['severity'] = $this->severity($worst);

            arsort($module['offenders']);
            $module['offenders'] = array_slice(
                array_map(
                    static fn ($k, $v) => ['signature' => $k, 'count' => $v],
                    array_keys($module['offenders']),
                    array_values($module['offenders'])
                ),
                0,
                4
            );

            arsort($module['tables']);
            $module['tables'] = array_slice(array_keys($module['tables']), 0, 5);
        }
        unset($module);

        return $modules;
    }

    private function severity(float $worstPerOp): string
    {
        if ($worstPerOp >= self::SEVERITY_CRITICAL) {
            return 'critical';
        }
        if ($worstPerOp >= self::SEVERITY_WARN) {
            return 'warn';
        }
        return 'good';
    }

    // --- db_logger deployment-config toggling -------------------------------------------------

    private function envPath(): string
    {
        return $this->directoryList->getPath(DirectoryList::CONFIG) . '/env.php';
    }

    private function dbLogPath(): string
    {
        return $this->root . '/var/debug/db.log';
    }

    /**
     * @return array|null the prior db_logger config to restore, or null if it was unset.
     *   A config identical to ours is treated as a leftover from a crashed run (not a real user
     *   setting), so we never "restore" query logging back on.
     */
    private function readDbLoggerConfig(): ?array
    {
        $config = $this->readEnv();
        $prior = $config['db_logger'] ?? null;
        if (is_array($prior) && $prior == self::PROFILING_DB_LOGGER) {
            return null;
        }
        return $prior;
    }

    private function setDbLoggerConfig(bool $on): void
    {
        $config = $this->readEnv();
        if ($on) {
            $config['db_logger'] = self::PROFILING_DB_LOGGER;
        } else {
            unset($config['db_logger']);
        }
        $this->writeEnv($config);
    }

    private function restoreDbLoggerConfig(?array $prior): void
    {
        $config = $this->readEnv();
        if ($prior === null) {
            unset($config['db_logger']);
        } else {
            $config['db_logger'] = $prior;
        }
        $this->writeEnv($config);
    }

    private function readEnv(): array
    {
        $config = include $this->envPath();
        return is_array($config) ? $config : [];
    }

    /**
     * Rewrite env.php atomically: every FPM request bootstraps by including this file, so a
     * partial read of a truncated file would fatal. Write to a sibling temp file and rename()
     * (atomic on the same filesystem) so readers always see a complete file.
     */
    private function writeEnv(array $config): void
    {
        $path = $this->envPath();
        $tmp = $path . '.fmtmp' . getmypid();
        $contents = "<?php\nreturn " . var_export($config, true) . ";\n";

        if (@file_put_contents($tmp, $contents, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new LocalizedException(__(
                'Could not write %1. The efficiency scan needs write access to app/etc/env.php '
                . '(it toggles query logging for the duration of the scan).',
                $path
            ));
        }
    }

    private function decodeWorkerOutput(string $raw): array
    {
        // The worker prints one JSON object as its last line; decode from the end to skip any
        // deprecation/xdebug noise on stdout.
        $lines = array_reverse(array_filter(array_map('trim', explode("\n", $raw)), 'strlen'));
        foreach ($lines as $line) {
            if ($line[0] !== '{') {
                continue;
            }
            try {
                $decoded = $this->json->unserialize($line);
                if (is_array($decoded) && isset($decoded['ops'], $decoded['area'], $decoded['label'])) {
                    return $decoded;
                }
            } catch (\Throwable $e) {
                // keep scanning earlier lines
            }
        }
        $this->logger->warning('FastMagento efficiency worker produced no parseable result line.');
        return ['ops' => 0, 'area' => 'unknown', 'label' => 'unknown'];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
