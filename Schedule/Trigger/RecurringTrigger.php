<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule\Trigger;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Cron-expression-based recurring trigger.
 *
 * This is the ONLY place dragonmantank/cron-expression is touched. If the library
 * is ever replaced or the dialect extended (Quartz L/W/#), only this class changes.
 *
 * Expression is validated eagerly at construction — fail-fast, not just at doctor time.
 */
final class RecurringTrigger implements Trigger
{
    private readonly CronExpression $cron;

    public function __construct(
        public readonly string       $expression,
        public readonly DateTimeZone $timezone,
        public readonly CronDialect  $dialect = CronDialect::FiveField,
    ) {
        $this->validateExpression($expression, $dialect);
        // dragonmantank is a 5-field library; for SixFieldSeconds we strip the leading
        // seconds field before handing to it (seconds precision is tracked by the slot
        // key at sub-minute granularity, not by the cron library itself).
        $this->cron = new CronExpression($this->fiveFieldExpression($expression));
    }

    public function nextRunAfter(DateTimeImmutable $after): DateTimeImmutable
    {
        // Convert the reference instant to the schedule's timezone before asking for next run.
        // The cron library is TZ-aware: it evaluates the expression in the given TZ,
        // correctly handling DST transitions (spring-forward gaps + fall-back doubles).
        $inTz = $after->setTimezone($this->timezone);

        $next = $this->cron->getNextRunDate($inTz, 0, false, $this->timezone->getName());

        return DateTimeImmutable::createFromMutable($next);
    }

    public function describe(): string
    {
        return sprintf('%s (%s, %s)', $this->expression, $this->timezone->getName(), $this->dialect->value);
    }

    /**
     * dragonmantank/cron-expression is a 5-field library.
     * For SixFieldSeconds, strip the leading seconds part; the remaining 5 fields are standard.
     */
    private function fiveFieldExpression(string $expression): string
    {
        if ($this->dialect !== CronDialect::SixFieldSeconds) {
            return $expression;
        }

        $parts = preg_split('/\s+/', trim($expression));

        return implode(' ', \array_slice($parts, 1));
    }

    private function validateExpression(string $expression, CronDialect $dialect): void
    {
        $parts = preg_split('/\s+/', trim($expression));

        if (\count($parts) !== $dialect->fieldCount()) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cron expression "%s" has %d field(s); %s dialect requires %d.',
                    $expression,
                    \count($parts),
                    $dialect->value,
                    $dialect->fieldCount(),
                )
            );
        }

        // For SixFieldSeconds, validate the 5-field remainder (seconds field has no cron lib equiv).
        $exprToValidate = $dialect === CronDialect::SixFieldSeconds
            ? implode(' ', \array_slice($parts, 1))
            : $expression;

        if (!CronExpression::isValidExpression($exprToValidate)) {
            throw new InvalidArgumentException(
                sprintf('Invalid cron expression: "%s"', $expression)
            );
        }
    }
}
