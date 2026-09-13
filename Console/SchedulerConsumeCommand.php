<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Scheduler\Engine\Consumer\FireQueueConsumer;
use Vortos\Scheduler\Engine\Consumer\WorkerRecyclePolicy;

/**
 * Entrypoint for the fire-queue consumer (S12) — drains vortos_scheduler_fire_queue,
 * dispatching each row through the CQRS CommandBus.
 *
 * Without this running, scheduled commands are recorded as "dispatched" in the
 * ledger but never actually execute — see SchedulerDoctor C11 for the health
 * check that catches a stalled/absent consumer.
 *
 * Usage (managed by supervisord via WorkerProcessDefinition, alongside scheduler:run):
 *   php /var/www/html/bin/console scheduler:consume --loop
 *
 * In --loop mode the process recycles itself — exits cleanly for supervisord to restart — before it
 * can reach memory_limit, and after --time-limit seconds. A worker that hits memory_limit instead
 * dies on a fatal mid-batch and strands the rows it claimed (FB-64).
 */
#[AsCommand(
    name: 'scheduler:consume',
    description: 'Drain the scheduler fire-queue, dispatching commands through the CQRS bus.',
)]
final class SchedulerConsumeCommand extends Command
{
    private bool $stopping = false;

    public function __construct(
        private readonly FireQueueConsumer $consumer,
        private readonly int $defaultBatchSize = 50,
        private readonly int $defaultPollIntervalSec = 2,
        private readonly int $defaultMemoryLimitMib = 0,
        private readonly int $defaultTimeLimitSec = 3600,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Run continuously until SIGTERM/SIGINT (production mode)')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows claimed per batch', (string) $this->defaultBatchSize)
            ->addOption('poll-interval', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep after an empty batch in --loop mode', (string) $this->defaultPollIntervalSec)
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'In --loop mode, exit cleanly for the supervisor to restart once real memory reaches this many MiB. 0 = 80% of memory_limit, -1 = never', (string) $this->defaultMemoryLimitMib)
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'In --loop mode, exit cleanly for the supervisor to restart after this many seconds. 0 = never', (string) $this->defaultTimeLimitSec);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize    = max(1, (int) $input->getOption('batch-size'));
        $pollInterval = max(1, (int) $input->getOption('poll-interval'));
        $loop         = (bool) $input->getOption('loop');

        if ($loop && \function_exists('pcntl_signal')) {
            $handler = function () use ($output): void {
                $output->writeln('<comment>Scheduler consumer stopping (signal received)…</comment>');
                $this->stopping = true;
            };

            \pcntl_signal(\SIGTERM, $handler);
            \pcntl_signal(\SIGINT,  $handler);
        }

        if (!$loop) {
            $processed = $this->consumer->consumeBatch($batchSize);
            $output->writeln(sprintf('<info>Processed %d row(s).</info>', $processed));

            return Command::SUCCESS;
        }

        $recycle = WorkerRecyclePolicy::fromOptions(
            (int) $input->getOption('memory-limit'),
            (int) $input->getOption('time-limit'),
            (string) \ini_get('memory_limit'),
            microtime(true),
        );

        $output->writeln('<info>Scheduler consumer starting (--loop).</info>');

        while (!$this->stopping) {
            // Checked before claiming, never after: a worker about to recycle must not take rows it
            // will not finish.
            $reason = $recycle->reasonToStop(\memory_get_usage(true), microtime(true));

            if ($reason !== null) {
                $output->writeln(sprintf('<comment>Scheduler consumer recycling: %s.</comment>', $reason));

                break;
            }

            $processed = $this->consumer->consumeBatch($batchSize);

            if ($processed === 0) {
                $this->sleepInterruptibly($pollInterval);
            }

            if (\function_exists('pcntl_signal_dispatch')) {
                \pcntl_signal_dispatch();
            }
        }

        $output->writeln('<info>Scheduler consumer stopped cleanly.</info>');

        return Command::SUCCESS;
    }

    private function sleepInterruptibly(int $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while (!$this->stopping && microtime(true) < $deadline) {
            usleep(200_000);

            if (\function_exists('pcntl_signal_dispatch')) {
                \pcntl_signal_dispatch();
            }
        }
    }

}
