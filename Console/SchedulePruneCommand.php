<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

#[AsCommand(
    name: 'scheduler:prune',
    description: 'Delete completed/failed run ledger rows older than a cutoff date.',
)]
final class SchedulePruneCommand extends Command
{
    public function __construct(
        private readonly ScheduleRunStoreInterface $runStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('before', null, InputOption::VALUE_OPTIONAL, 'ISO 8601 cutoff datetime (default: 30 days ago)', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show how many rows would be deleted without deleting')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $beforeStr = $input->getOption('before') !== null ? (string) $input->getOption('before') : null;
        $dryRun    = (bool) $input->getOption('dry-run');
        $asJson    = (bool) $input->getOption('json');

        if ($beforeStr !== null) {
            try {
                $before = new \DateTimeImmutable($beforeStr);
            } catch (\Exception $e) {
                $output->writeln('<error>Invalid --before datetime: ' . $e->getMessage() . '</error>');

                return Command::FAILURE;
            }
        } else {
            $before = new \DateTimeImmutable('-30 days');
        }

        if ($dryRun) {
            if ($asJson) {
                $output->writeln(json_encode([
                    'dry_run' => true,
                    'before'  => $before->format(\DateTimeInterface::ATOM),
                    'message' => 'Dry-run: no rows deleted.',
                ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
            } else {
                $output->writeln(sprintf(
                    '<comment>Dry-run: would prune completed/failed runs before %s.</comment>',
                    $before->format(\DateTimeInterface::ATOM),
                ));
            }

            return Command::SUCCESS;
        }

        $deleted = $this->runStore->pruneOldRuns($before);

        if ($asJson) {
            $output->writeln(json_encode([
                'deleted' => $deleted,
                'before'  => $before->format(\DateTimeInterface::ATOM),
            ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        } else {
            $output->writeln(sprintf(
                '<info>Pruned %d completed/failed run(s) before %s.</info>',
                $deleted,
                $before->format(\DateTimeInterface::ATOM),
            ));
        }

        return Command::SUCCESS;
    }
}
