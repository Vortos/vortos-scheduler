<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Scheduler\Doctor\SchedulerDoctor;
use Vortos\Scheduler\Doctor\SchedulerDoctorStatus;

#[AsCommand(
    name: 'scheduler:doctor',
    description: 'Run scheduler preflight checks (fail-closed: exits 1 on any failure).',
)]
final class ScheduleDoctorCommand extends Command
{
    public function __construct(
        private readonly SchedulerDoctor $doctor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output report as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $asJson = (bool) $input->getOption('json');
        $report = $this->doctor->run();

        if ($asJson) {
            $output->writeln($report->toJson());

            return $report->exitCode();
        }

        $table = new Table($output);
        $table->setHeaders(['Check', 'Status', 'Summary', 'Detail']);

        foreach ($report->findings as $finding) {
            $statusLabel = match ($finding->status) {
                SchedulerDoctorStatus::Pass => '<info>PASS</info>',
                SchedulerDoctorStatus::Fail => '<error>FAIL</error>',
                SchedulerDoctorStatus::Skip => '<comment>SKIP</comment>',
            };

            $table->addRow([
                $finding->checkId,
                $statusLabel,
                $finding->summary,
                $finding->detail !== '' ? substr($finding->detail, 0, 100) : '',
            ]);
        }

        $table->render();

        $passed  = $report->countByStatus(SchedulerDoctorStatus::Pass);
        $failed  = $report->countByStatus(SchedulerDoctorStatus::Fail);
        $skipped = $report->countByStatus(SchedulerDoctorStatus::Skip);

        $output->writeln('');
        $output->writeln(sprintf('Passed: %d  Failed: %d  Skipped: %d', $passed, $failed, $skipped));

        if ($report->isClear()) {
            $output->writeln('<info>All checks passed — scheduler is healthy.</info>');
        } else {
            $output->writeln(sprintf('<error>%d check(s) failed — see remediation above.</error>', $failed));
        }

        return $report->exitCode();
    }
}
