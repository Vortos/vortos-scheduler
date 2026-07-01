<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Scheduler\Security\FourEyesGate;

#[AsCommand(
    name: 'scheduler:approve',
    description: 'Approve (or reject) a pending 4-eyes approval request.',
)]
final class ScheduleApproveCommand extends Command
{
    public function __construct(
        private readonly FourEyesGate $fourEyesGate,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('approval-id', InputArgument::REQUIRED, 'Approval request ID')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Actor ID (approver identity)')
            ->addOption('reject', null, InputOption::VALUE_NONE, 'Reject instead of approve');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $approvalId = (string) $input->getArgument('approval-id');
        $actorId    = (string) $input->getOption('actor');
        $reject     = (bool) $input->getOption('reject');

        try {
            if ($reject) {
                $request = $this->fourEyesGate->reject($approvalId, $actorId);
                $output->writeln(sprintf('<comment>Approval request "%s" rejected.</comment>', $approvalId));
            } else {
                $request = $this->fourEyesGate->approve($approvalId, $actorId);
                $output->writeln(sprintf('<info>Approval request "%s" approved.</info>', $approvalId));
            }
        } catch (\Vortos\Scheduler\Security\Exception\SelfApprovalException $e) {
            $output->writeln('<error>Self-approval is not permitted: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>Approval not found: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Resolved by: %s', $actorId));

        return Command::SUCCESS;
    }
}
