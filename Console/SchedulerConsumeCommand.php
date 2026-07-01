<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Scheduler\Engine\Consumer\FireQueueConsumer;

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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Run continuously until SIGTERM/SIGINT (production mode)')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows claimed per batch', (string) $this->defaultBatchSize)
            ->addOption('poll-interval', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep after an empty batch in --loop mode', (string) $this->defaultPollIntervalSec);
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

        $output->writeln('<info>Scheduler consumer starting (--loop).</info>');

        while (!$this->stopping) {
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
