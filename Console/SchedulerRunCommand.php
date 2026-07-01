<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Scheduler\Engine\SchedulerDaemon;

/**
 * Entrypoint for the leader-elected scheduler daemon.
 *
 * Installs SIGTERM / SIGINT handlers that call daemon->stop() so the
 * in-progress tick finishes cleanly before the process exits. Supervisor's
 * stopwaitsecs must be >= drainDeadline (configured in WorkerProcessDefinition).
 *
 * Usage (managed by supervisord via WorkerProcessDefinition):
 *   php /var/www/html/bin/console scheduler:run
 */
#[AsCommand(
    name: 'scheduler:run',
    description: 'Run the Vortos Scheduler daemon (leader-elected, sharded).',
)]
final class SchedulerRunCommand extends Command
{
    public function __construct(
        private readonly SchedulerDaemon $daemon,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (\function_exists('pcntl_signal')) {
            $handler = function () use ($output): void {
                $output->writeln('<comment>Scheduler daemon stopping (signal received)…</comment>');
                $this->daemon->stop();
            };

            \pcntl_signal(\SIGTERM, $handler);
            \pcntl_signal(\SIGINT,  $handler);
        }

        $output->writeln('<info>Scheduler daemon starting.</info>');
        $this->daemon->run();
        $output->writeln('<info>Scheduler daemon stopped cleanly.</info>');

        return Command::SUCCESS;
    }
}
