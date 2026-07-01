<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException;
use Vortos\Scheduler\Service\ScheduleService;

#[AsCommand(
    name: 'scheduler:retention:set',
    description: 'Set (or replace) a tenant\'s run-retention override. --days=0 is a permanent legal-hold exemption.',
)]
final class RetentionOverrideSetCommand extends Command
{
    public function __construct(
        private readonly ScheduleService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant ID')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention days (>= 0; 0 = legal hold, never pruned)')
            ->addOption('reason', null, InputOption::VALUE_OPTIONAL, 'Reason for the override (stored in audit log)', null)
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Actor ID (operator identity for RBAC + audit)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actorId  = (string) $input->getOption('actor');
        $tenantId = (string) $input->getOption('tenant');
        $reason   = $input->getOption('reason') !== null ? (string) $input->getOption('reason') : null;
        $actor    = new CliActorIdentity($actorId);

        if (!is_numeric($input->getOption('days'))) {
            $output->writeln('<error>--days must be a non-negative integer.</error>');

            return Command::FAILURE;
        }

        $days = (int) $input->getOption('days');

        try {
            $this->service->setRunRetentionOverride($tenantId, $days, $actor, $reason);
        } catch (ScheduleAccessDeniedException $e) {
            $output->writeln('<error>Access denied: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } catch (\LogicException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $label = $days === 0 ? 'legal hold (permanently exempt)' : sprintf('%d day(s)', $days);
        $output->writeln(sprintf(
            '<info>Retention override for tenant "%s" set to %s.</info>',
            $tenantId,
            $label,
        ));

        return Command::SUCCESS;
    }
}
