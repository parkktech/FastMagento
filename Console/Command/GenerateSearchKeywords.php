<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use ParkkTech\FastMagento\Model\Ai\SearchKeywordGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: populate the `fm_search_keywords` attribute with an AI keyword/synonym layer.
 *
 *   bin/magento fastmagento:search-keywords:generate [--from] [--to] [--batch] [--limit]
 *                                                     [--force] [--dry-run]
 *
 * This is the intended way to run generation over the whole catalogue — it is off the request
 * path, resumable (skips products that already have keywords unless --force), and best-effort per
 * batch. Reindex catalogsearch_fulltext afterwards to make the new keywords searchable.
 */
class GenerateSearchKeywords extends Command
{
    public function __construct(
        private readonly SearchKeywordGenerator $generator,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('fastmagento:search-keywords:generate')
            ->setDescription('Generate the AI search-keyword layer (fm_search_keywords) for products.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start product entity_id (inclusive).')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End product entity_id (inclusive).')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Products per API call (default 25, max 100).')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max products to process this run (0 = all).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Regenerate even if keywords already exist.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not call the API or write; show what would run.');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable $e) {
            // Area already set (e.g. under a wrapping command) — safe to continue.
        }

        if (!$this->generator->isEnabled() && !$input->getOption('dry-run')) {
            $output->writeln('<comment>Search keyword generation is disabled.</comment> '
                . 'Enable "AI Search Keywords" under Stores > Configuration > FastMagento > '
                . 'Instant Search & Relevance, or pass --dry-run to preview.');
            return Command::FAILURE;
        }

        $options = [
            'from' => (int) $input->getOption('from'),
            'to' => (int) $input->getOption('to'),
            'batch' => (int) $input->getOption('batch'),
            'limit' => (int) $input->getOption('limit'),
            'force' => (bool) $input->getOption('force'),
            'dry_run' => (bool) $input->getOption('dry-run'),
        ];

        if ($options['dry_run']) {
            $output->writeln('<info>Dry run</info> — no API calls, no writes.');
        }

        $start = microtime(true);
        $progress = function (array $event) use ($output): void {
            $output->writeln(sprintf(
                '  batch %d: %d updated, %d skipped, %d failed (ids %d–%d) %s',
                $event['batch'],
                $event['updated'],
                $event['skipped'],
                $event['failed'],
                (int) ($event['ids'][0] ?? 0),
                (int) (end($event['ids']) ?: 0),
                $event['message'] === 'ok' ? '' : '— ' . $event['message']
            ));
        };

        try {
            $stats = $this->generator->run($options, $progress);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $seconds = round(microtime(true) - $start, 1);
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done.</info> %d processed, %d updated, %d skipped, %d failed across %d batch(es) in %ss.',
            $stats['processed'],
            $stats['updated'],
            $stats['skipped'],
            $stats['failed'],
            $stats['batches'],
            $seconds
        ));
        if ($stats['updated'] > 0 && !$options['dry_run']) {
            $output->writeln('Run <comment>bin/magento indexer:reindex catalogsearch_fulltext</comment> '
                . 'to make the new keywords searchable.');
        }

        return Command::SUCCESS;
    }
}
