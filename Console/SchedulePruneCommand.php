<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Scheduler\Audit\SchedulerAuditProjector;
use Vortos\Scheduler\Retention\RunRetentionSweeper;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

/**
 * Manual prune entrypoint. Two modes (SCHEDULER_AUTO_PRUNE_IMPL_PLAN.md item 10):
 *
 *  - Default (no --before): delegates to RunRetentionSweeper — honors per-tenant
 *    overrides and the global default exactly like the automatic daily schedule.
 *    This is the mode operators should reach for.
 *
 *  - --before <datetime> [--tenant <id>]: bypasses the resolver entirely and prunes
 *    at an explicit operator-chosen cutoff (e.g. "force-delete everything before
 *    this incident, regardless of policy"). Audited with resolved=false so it is
 *    visibly distinguishable from a policy-driven prune in the ledger.
 *
 * Both modes write a `runs.pruned` audit entry (actorId = the real operator, not
 * 'system') when an audit projector is wired.
 */
#[AsCommand(
    name: 'scheduler:prune',
    description: 'Delete completed/failed run ledger rows — policy-aware by default, or an explicit --before cutoff.',
)]
final class SchedulePruneCommand extends Command
{
    public function __construct(
        private readonly ScheduleRunStoreInterface $runStore,
        private readonly ?RunRetentionSweeper       $sweeper = null,
        private readonly ?SchedulerAuditProjector   $audit = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('before', null, InputOption::VALUE_OPTIONAL, 'Explicit ISO 8601 cutoff — bypasses per-tenant/global policy resolution', null)
            ->addOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Scope to one tenant — only valid together with --before', null)
            ->addOption('actor', null, InputOption::VALUE_OPTIONAL, 'Actor ID for the audit log', 'cli-operator')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be pruned without deleting')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $beforeStr = $input->getOption('before') !== null ? (string) $input->getOption('before') : null;
        $tenantId  = $input->getOption('tenant') !== null ? (string) $input->getOption('tenant') : null;
        $actorId   = (string) $input->getOption('actor');
        $dryRun    = (bool) $input->getOption('dry-run');
        $asJson    = (bool) $input->getOption('json');
        $bypass    = $beforeStr !== null;

        if ($tenantId !== null && !$bypass) {
            $output->writeln(
                '<error>--tenant is only valid together with --before; the default '
                . 'policy-aware sweep already covers every tenant at its own resolved retention.</error>',
            );

            return Command::FAILURE;
        }

        $before = null;

        if ($bypass) {
            try {
                $before = new \DateTimeImmutable($beforeStr);
            } catch (\Exception $e) {
                $output->writeln('<error>Invalid --before datetime: ' . $e->getMessage() . '</error>');

                return Command::FAILURE;
            }
        }

        if ($dryRun) {
            return $this->reportDryRun($output, $asJson, $bypass, $before, $tenantId);
        }

        if ($bypass) {
            $result = $this->runStore->pruneOldRuns($before, $tenantId);
            $this->audit?->onRunsPruned($actorId, $tenantId, $result->deletedCount, $before, $result->truncated, resolved: false);

            return $this->reportResult($output, $asJson, $result->deletedCount, $result->truncated, 'bypass');
        }

        if ($this->sweeper === null) {
            $output->writeln(
                '<error>Policy-aware sweep is unavailable (retention override store not registered — DBAL missing?). '
                . 'Use --before for an explicit cutoff instead.</error>',
            );

            return Command::FAILURE;
        }

        $sweepResult = $this->sweeper->sweep(trigger: 'manual', actorId: $actorId);

        return $this->reportResult($output, $asJson, $sweepResult->deletedCount, $sweepResult->truncated, 'policy');
    }

    private function reportDryRun(
        OutputInterface     $output,
        bool                 $asJson,
        bool                 $bypass,
        ?\DateTimeImmutable  $before,
        ?string              $tenantId,
    ): int {
        $message = $bypass
            ? sprintf(
                'Dry-run: would prune completed/failed runs before %s%s.',
                $before->format(\DateTimeInterface::ATOM),
                $tenantId !== null ? " (tenant \"{$tenantId}\")" : '',
            )
            : 'Dry-run: would run the policy-aware sweep (per-tenant overrides + global default).';

        if ($asJson) {
            $output->writeln(json_encode([
                'dry_run' => true,
                'mode'    => $bypass ? 'bypass' : 'policy',
                'before'  => $before?->format(\DateTimeInterface::ATOM),
                'tenant'  => $tenantId,
                'message' => $message,
            ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        } else {
            $output->writeln('<comment>' . $message . '</comment>');
        }

        return Command::SUCCESS;
    }

    private function reportResult(OutputInterface $output, bool $asJson, int $deleted, bool $truncated, string $mode): int
    {
        if ($asJson) {
            $output->writeln(json_encode([
                'deleted'   => $deleted,
                'truncated' => $truncated,
                'mode'      => $mode,
            ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        } else {
            $output->writeln(sprintf(
                '<info>Pruned %d completed/failed run(s)%s.</info>',
                $deleted,
                $truncated ? ' (budget exhausted — more may remain; will continue next run)' : '',
            ));
        }

        return Command::SUCCESS;
    }
}
