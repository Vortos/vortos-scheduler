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
    name: 'scheduler:retention:remove',
    description: 'Remove a tenant\'s run-retention override, restoring the global default.',
)]
final class RetentionOverrideRemoveCommand extends Command
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
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Actor ID (operator identity for RBAC + audit)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actorId  = (string) $input->getOption('actor');
        $tenantId = (string) $input->getOption('tenant');
        $actor    = new CliActorIdentity($actorId);

        try {
            $this->service->removeRunRetentionOverride($tenantId, $actor);
        } catch (ScheduleAccessDeniedException $e) {
            $output->writeln('<error>Access denied: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } catch (\LogicException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Retention override for tenant "%s" removed — global default now applies.</info>',
            $tenantId,
        ));

        return Command::SUCCESS;
    }
}
