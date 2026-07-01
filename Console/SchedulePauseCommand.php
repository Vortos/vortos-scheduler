<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Service\ScheduleService;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;

#[AsCommand(
    name: 'scheduler:pause',
    description: 'Pause a schedule (prevents daemon from firing it).',
)]
final class SchedulePauseCommand extends Command
{
    public function __construct(
        private readonly ScheduleService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Schedule ID (UUID)')
            ->addOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Tenant ID (omit for system scope)', null)
            ->addOption('reason', null, InputOption::VALUE_OPTIONAL, 'Reason for pausing (stored in audit log)', null)
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Actor ID (operator identity for RBAC + audit)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actorId  = (string) $input->getOption('actor');
        $tenantId = $input->getOption('tenant') !== null ? (string) $input->getOption('tenant') : null;
        $reason   = $input->getOption('reason') !== null ? (string) $input->getOption('reason') : null;
        $id       = ScheduleId::fromString((string) $input->getArgument('id'));
        $actor    = new CliActorIdentity($actorId);

        try {
            $schedule = $this->service->pause($id, $tenantId, $actor, $reason);
        } catch (ScheduleNotFoundException $e) {
            $output->writeln('<error>Schedule not found: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } catch (\Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException $e) {
            $output->writeln('<error>Access denied: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Schedule "%s" (%s) paused successfully.</info>',
            $schedule->name,
            $id->toString(),
        ));

        return Command::SUCCESS;
    }
}
