<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Command\Handler;

use Vortos\Cqrs\Attribute\AsCommandHandler;
use Vortos\Scheduler\Command\PruneSchedulerRunsCommand;
use Vortos\Scheduler\Retention\RunRetentionSweeper;

/**
 * Handles the daily auto-prune fire. Thin by design: all sweep logic (resolving
 * per-tenant overrides vs. the global default, batching, audit, metrics, tracing)
 * lives in RunRetentionSweeper, shared with the manual `scheduler:prune` CLI path.
 *
 * Registered explicitly (tagged `vortos.command_handler`) by
 * SchedulerExtension::registerConsumer(), not via #[AsCommandHandler] resource
 * autodiscovery — this package always wires its own services explicitly. The
 * attribute is kept for documentation/consistency with the rest of the framework.
 */
#[AsCommandHandler]
final class PruneSchedulerRunsHandler
{
    public function __construct(
        private readonly RunRetentionSweeper $sweeper,
    ) {}

    public function __invoke(PruneSchedulerRunsCommand $command): void
    {
        $this->sweeper->sweep(trigger: 'auto');
    }
}
