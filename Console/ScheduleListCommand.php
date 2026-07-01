<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Schedule\ScheduleStatus;

#[AsCommand(
    name: 'scheduler:list',
    description: 'List all schedules (static + dynamic) with runtime status.',
)]
final class ScheduleListCommand extends Command
{
    public function __construct(
        private readonly ScheduleResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('next', null, InputOption::VALUE_OPTIONAL, 'Show next N fire times per schedule (max 20)', 0)
            ->addOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Filter by tenant ID (null = system scope)', null)
            ->addOption('status', null, InputOption::VALUE_OPTIONAL, 'Filter by status: active|paused|disabled|all', 'all')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nextCount  = min((int) ($input->getOption('next') ?? 0), 20);
        $tenantId   = $input->getOption('tenant') !== null ? (string) $input->getOption('tenant') : null;
        $statusFilter = (string) ($input->getOption('status') ?? 'all');
        $asJson     = (bool) $input->getOption('json');

        $now = new \DateTimeImmutable();
        $rows = [];

        foreach ($this->resolver->fullView($tenantId) as $schedule) {
            if ($statusFilter !== 'all') {
                $filterStatus = ScheduleStatus::tryFrom($statusFilter);
                if ($filterStatus !== null && $schedule->status !== $filterStatus) {
                    continue;
                }
            }

            $nextTimes = [];
            if ($nextCount > 0) {
                $cursor = $now;
                for ($i = 0; $i < $nextCount; $i++) {
                    $next = $schedule->trigger->nextRunAfter($cursor);
                    if ($next === null) {
                        break;
                    }

                    $nextTimes[] = $next->format(\DateTimeInterface::ATOM);
                    $cursor = $next;
                }
            }

            $rows[] = [
                'id'       => $schedule->id->toString(),
                'name'     => $schedule->name,
                'source'   => $schedule->source->value,
                'status'   => $schedule->status->value,
                'trigger'  => $schedule->trigger->describe(),
                'tenant'   => $schedule->tenantId ?? 'system',
                'next'     => $nextTimes,
            ];
        }

        if ($asJson) {
            $output->writeln(json_encode($rows, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        if ($rows === []) {
            $output->writeln('<info>No schedules found.</info>');

            return Command::SUCCESS;
        }

        $headers = ['ID', 'Name', 'Source', 'Status', 'Trigger', 'Tenant'];
        if ($nextCount > 0) {
            $headers[] = 'Next fire time(s)';
        }

        $table = new Table($output);
        $table->setHeaders($headers);

        foreach ($rows as $row) {
            $tableRow = [
                substr($row['id'], 0, 8) . '...',
                $row['name'],
                $row['source'],
                $row['status'],
                $row['trigger'],
                $row['tenant'],
            ];

            if ($nextCount > 0) {
                $tableRow[] = implode("\n", $row['next']) ?: '-';
            }

            $table->addRow($tableRow);
        }

        $table->render();
        $output->writeln(sprintf('<info>%d schedule(s) found.</info>', count($rows)));

        return Command::SUCCESS;
    }
}
