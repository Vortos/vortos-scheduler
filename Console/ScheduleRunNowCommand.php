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
use Vortos\Scheduler\Registry\ScheduleResolver;
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
        private readonly ?ScheduleResolver $resolver = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Schedule ID (UUID) or name (as shown by scheduler:list)')
            ->addOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Tenant ID (omit for system scope)', null)
            ->addOption('reason', null, InputOption::VALUE_OPTIONAL, 'Optional reason for audit log', null)
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Actor ID (operator identity for RBAC + audit)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actorId  = (string) $input->getOption('actor');
        $tenantId = $input->getOption('tenant') !== null ? (string) $input->getOption('tenant') : null;
        $reason   = $input->getOption('reason') !== null ? (string) $input->getOption('reason') : null;
        $rawId    = (string) $input->getArgument('id');

        // R8-11 (B7): accept the schedule NAME as well as its UUID — scheduler:list shows names, so a
        // UUID-only requirement was a footgun.
        try {
            $id = $this->resolveId($rawId, $tenantId);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

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

    /**
     * Resolve the argument to a ScheduleId. A UUID is used directly; anything else is treated as a
     * schedule name and looked up (static + dynamic) via the resolver, scoped to the given tenant.
     *
     * @throws \InvalidArgumentException on an unknown name, an ambiguous name, or when name lookup is
     *                                   unavailable (no resolver wired).
     */
    private function resolveId(string $raw, ?string $tenantId): ScheduleId
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw) === 1) {
            return ScheduleId::fromString($raw);
        }

        if ($this->resolver === null) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid UUID and name lookup is not available here.', $raw));
        }

        $matches = [];
        foreach ($this->resolver->fullView($tenantId) as $schedule) {
            if ($schedule->name === $raw) {
                $matches[] = $schedule;
            }
        }

        if ($matches === []) {
            throw new \InvalidArgumentException(sprintf('No schedule named "%s"%s.', $raw, $tenantId !== null ? ' for tenant ' . $tenantId : ''));
        }

        if (count($matches) > 1) {
            $ids = implode(', ', array_map(static fn ($s): string => $s->id->toString(), $matches));
            throw new \InvalidArgumentException(sprintf('Name "%s" is ambiguous (%d matches: %s). Re-run with the UUID or a --tenant filter.', $raw, count($matches), $ids));
        }

        return $matches[0]->id;
    }
}
