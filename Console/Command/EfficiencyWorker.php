<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Serialize\Serializer\Json;
use ParkkTech\FastMagento\Model\Efficiency\ScenarioRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Internal worker for {@see EfficiencyScan}. Runs ONE profiling scenario in a fresh process
 * (so it bootstraps with db_logger already enabled and its SQL is captured), then prints a
 * single JSON line describing what it did. Not intended to be run by hand.
 *
 *   bin/magento fastmagento:efficiency:worker <scenario> <sampleSize>
 */
class EfficiencyWorker extends Command
{
    public function __construct(
        private readonly ScenarioRunner $runner,
        private readonly Json $json,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('fastmagento:efficiency:worker')
            ->setDescription('[internal] Run one FastMagento efficiency scenario and emit its metadata as JSON.')
            ->addArgument('scenario', InputArgument::REQUIRED, 'Scenario key.')
            ->addArgument('sample', InputArgument::OPTIONAL, 'Sample size.', '50');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (\Throwable $e) {
            // Area already set — safe to continue.
        }

        $scenario = (string) $input->getArgument('scenario');
        $sample = max(1, (int) $input->getArgument('sample'));

        try {
            $result = $this->runner->run($scenario, $sample);
        } catch (\Throwable $e) {
            // Emit a valid (empty) result and exit 0 so one bad scenario doesn't abort the whole
            // scan — Shell::execute() throws on any non-zero exit code.
            $output->writeln($this->json->serialize(['ops' => 0, 'area' => 'error', 'label' => $e->getMessage()]));
            return Command::SUCCESS;
        }

        $output->writeln($this->json->serialize($result));
        return Command::SUCCESS;
    }
}
