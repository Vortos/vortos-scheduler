<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Security\Exception\FourEyesApprovalRequiredException;
use Vortos\Scheduler\Service\ScheduleService;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;

#[AsCommand(
    name: 'scheduler:run-now',
    description: 'Manually trigger a schedule to fire immediately.',
)]
final class ScheduleRunNowCommand extends Command
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
            ->addOption('reason', null, InputOption::VALUE_OPTIONAL, 'Optional reason for audit log', null)
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
            $result = $this->service->runNow($id, $tenantId, $actor, $reason);
        } catch (ScheduleNotFoundException $e) {
            $output->writeln('<error>Schedule not found: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } catch (FourEyesApprovalRequiredException $e) {
            $output->writeln('<comment>Approval required: ' . $e->getMessage() . '</comment>');
            $output->writeln('Use <info>scheduler:approve</info> to approve the request, then retry.');

            return Command::FAILURE;
        } catch (\DomainException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $resultLabel = match ($result) {
            FireDispatchResult::Dispatched       => '<info>Dispatched</info>',
            FireDispatchResult::AlreadyDispatched => '<comment>Already dispatched (idempotent)</comment>',
            FireDispatchResult::SkippedOverlap   => '<comment>Skipped (prior run still in-flight)</comment>',
            FireDispatchResult::Deferred         => '<comment>Deferred (jitter window not elapsed)</comment>',
        };

        $output->writeln('Result: ' . $resultLabel);

        return Command::SUCCESS;
    }
}
