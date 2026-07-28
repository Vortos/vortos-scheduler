<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Observability\SchedulerMetrics;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;

/**
 * In-memory {@see MetricsInterface} wrapper that captures every SchedulerMetrics call for
 * assertion in unit and integration tests.
 *
 * Usage:
 *   $recording = new RecordingSchedulerMetrics();
 *   // use $recording->metrics as the MetricsInterface, or $recording->schedulerMetrics directly
 *   $recording->assertCounterIncremented('vortos_scheduler_fires_total');
 */
final class RecordingSchedulerMetrics
{
    public readonly SchedulerMetrics $schedulerMetrics;

    /** @var list<array{name:string,labels:array<string,string>,by:float}> */
    public array $counters = [];

    /** @var list<array{name:string,labels:array<string,string>,value:float}> */
    public array $histograms = [];

    /** @var list<array{name:string,labels:array<string,string>,value:float}> */
    public array $gauges = [];

    public function __construct()
    {
        $self = $this;

        $metricsInterface = new class($self) implements MetricsInterface {
            public function __construct(private RecordingSchedulerMetrics $recording) {}

            /** @param array<string, string> $labels */
            public function counter(string $name, array $labels = []): CounterInterface
            {
                return new class($this->recording, $name, $labels) implements CounterInterface {
                    /** @param array<string, string> $labels */
                    public function __construct(
                        private RecordingSchedulerMetrics $recording,
                        private string $name,
                        private array $labels,
                    ) {}

                    public function increment(float $by = 1.0): void
                    {
                        $this->recording->counters[] = ['name' => $this->name, 'labels' => $this->labels, 'by' => $by];
                    }
                };
            }

            /** @param array<string, string> $labels */
            public function gauge(string $name, array $labels = []): GaugeInterface
            {
                return new class($this->recording, $name, $labels) implements GaugeInterface {
                    /** @param array<string, string> $labels */
                    public function __construct(
                        private RecordingSchedulerMetrics $recording,
                        private string $name,
                        private array $labels,
                    ) {}

                    public function set(float $value): void
                    {
                        $this->recording->gauges[] = ['name' => $this->name, 'labels' => $this->labels, 'value' => $value];
                    }

                    public function increment(float $by = 1.0): void {}
                    public function decrement(float $by = 1.0): void {}
                };
            }

            /** @param array<string, string> $labels */
            public function histogram(string $name, array $labels = []): HistogramInterface
            {
                return new class($this->recording, $name, $labels) implements HistogramInterface {
                    /** @param array<string, string> $labels */
                    public function __construct(
                        private RecordingSchedulerMetrics $recording,
                        private string $name,
                        private array $labels,
                    ) {}

                    public function observe(float $value): void
                    {
                        $this->recording->histograms[] = ['name' => $this->name, 'labels' => $this->labels, 'value' => $value];
                    }
                };
            }
        };

        $this->schedulerMetrics = new SchedulerMetrics($metricsInterface);
    }

    // ── Assertion helpers ──────────────────────────────────────────────────────

    public function assertCounterIncremented(string $name): void
    {
        $found = array_filter($this->counters, fn (array $c) => $c['name'] === $name);

        if ($found === []) {
            throw new \RuntimeException("Counter '{$name}' was never incremented. Recorded: " . implode(', ', array_column($this->counters, 'name')));
        }
    }

    /** @param array<string, string> $labels */
    public function assertCounterIncrementedWith(string $name, array $labels): void
    {
        foreach ($this->counters as $c) {
            if ($c['name'] === $name) {
                $match = true;
                foreach ($labels as $k => $v) {
                    if (($c['labels'][$k] ?? null) !== $v) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    return;
                }
            }
        }

        throw new \RuntimeException("Counter '{$name}' with labels " . json_encode($labels) . " was never incremented.");
    }

    public function assertHistogramObserved(string $name): void
    {
        $found = array_filter($this->histograms, fn (array $h) => $h['name'] === $name);

        if ($found === []) {
            throw new \RuntimeException("Histogram '{$name}' was never observed.");
        }
    }

    public function assertGaugeSet(string $name): void
    {
        $found = array_filter($this->gauges, fn (array $g) => $g['name'] === $name);

        if ($found === []) {
            throw new \RuntimeException("Gauge '{$name}' was never set.");
        }
    }

    public function assertNothingEmitted(): void
    {
        if ($this->counters !== [] || $this->histograms !== [] || $this->gauges !== []) {
            throw new \RuntimeException('Expected no metrics to be emitted, but found some.');
        }
    }

    /**
     * Every label set recorded against a counter, in order — for assertions about the label VALUE
     * rather than just the fact that something was counted.
     *
     * @return list<array<string, string>>
     */
    public function counterLabelsFor(string $name): array
    {
        $labels = [];
        foreach ($this->counters as $c) {
            if ($c['name'] === $name) {
                $labels[] = $c['labels'];
            }
        }

        return $labels;
    }

    public function countFor(string $name): int
    {
        return count(array_filter($this->counters, fn (array $c) => $c['name'] === $name));
    }
}
