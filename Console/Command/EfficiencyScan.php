<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use ParkkTech\FastMagento\Model\Efficiency\Profiler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: run an extension-efficiency scan and write the report the admin monitor renders.
 *
 *   bin/magento fastmagento:efficiency:scan [--sample=50]
 *
 * Profiles the hot paths (product load, category grid, search), attributes every SQL query to
 * the third-party module that triggered it, and measures the FastMagento batched loader against
 * native hydration. Safe to run any time; it restores db_logger to its prior state when done.
 * Do not run it during a full reindex — the two would compete for the db log.
 */
class EfficiencyScan extends Command
{
    public function __construct(
        private readonly Profiler $profiler,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('fastmagento:efficiency:scan')
            ->setDescription('Profile third-party extension DB overhead on the hot paths; writes the admin report.')
            ->addOption('sample', null, InputOption::VALUE_REQUIRED, 'Products/rows per scenario (default 50).');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable $e) {
            // Area already set — safe to continue.
        }

        $sample = (int) ($input->getOption('sample') ?? 50);
        $sample = max(1, min($sample, 500));

        $output->writeln("<info>FastMagento — extension efficiency scan (sample=$sample)</info>");

        try {
            $report = $this->profiler->run($sample, static function (string $msg) use ($output) {
                $output->writeln('  ' . $msg);
            });
        } catch (\Throwable $e) {
            $output->writeln('<error>Scan failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done.</info> %d third-party modules touch the hot paths (%d queries attributed).',
            $report['totals']['third_party_modules'],
            $report['totals']['third_party_queries']
        ));
        foreach ($report['modules'] as $module) {
            $output->writeln(sprintf(
                '  [%-8s] %-32s worst %.1f q/op',
                strtoupper($module['severity']),
                $module['title'],
                $module['worst_per_op']
            ));
        }
        $output->writeln('');
        $output->writeln('<info>Report written.</info> View it under Admin → FastMagento → Extension Efficiency.');

        return Command::SUCCESS;
    }
}
