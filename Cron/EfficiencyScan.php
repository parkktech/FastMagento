<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use ParkkTech\FastMagento\Model\Efficiency\Profiler;
use Psr\Log\LoggerInterface;

/**
 * Scheduled extension-efficiency scan. Refreshes the report the admin monitor renders.
 *
 * Gated by fastmagento/efficiency/cron_enabled (off by default) so a store opts in before a
 * scan — which briefly toggles db_logger — runs unattended. The schedule itself is configurable
 * via fastmagento/efficiency/cron_expr (crontab.xml <config_path>).
 */
class EfficiencyScan
{
    private const XML_ENABLED = 'fastmagento/efficiency/cron_enabled';
    private const XML_SAMPLE = 'fastmagento/efficiency/sample_size';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Profiler $profiler,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_ENABLED)) {
            return;
        }

        $sample = (int) $this->scopeConfig->getValue(self::XML_SAMPLE);
        $sample = $sample > 0 ? min($sample, 500) : 50;

        try {
            $report = $this->profiler->run($sample);
            $this->logger->info(sprintf(
                'FastMagento efficiency scan complete: %d third-party modules, %d queries attributed.',
                $report['totals']['third_party_modules'],
                $report['totals']['third_party_queries']
            ));
        } catch (\Throwable $e) {
            $this->logger->error('FastMagento efficiency scan failed: ' . $e->getMessage());
        }
    }
}
