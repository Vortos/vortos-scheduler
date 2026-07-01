<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule;

use DateTimeZone;
use InvalidArgumentException;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Policy\Jitter;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Trigger\Trigger;

/**
 * Immutable schedule definition.
 *
 * Invariants enforced at construction:
 *  - name matches /^[a-z0-9][a-z0-9_-]*$/  (lowercase, kebab/snake, no whitespace)
 *  - metadata is array<string, string> (safe for serialization + label propagation)
 */
final readonly class Schedule
{
    /** @param array<string, string> $metadata */
    public function __construct(
        public ScheduleId     $id,
        public string         $name,
        public ScheduleSource $source,
        public Trigger        $trigger,
        public CommandSpec    $command,
        public MisfirePolicy  $misfire,
        public OverlapPolicy  $overlap,
        public DateTimeZone   $timezone,
        public ?Jitter        $jitter,
        public ScheduleStatus $status,
        public ?string        $tenantId,
        public bool           $sensitive = false,
        public array          $metadata  = [],
        public int            $version   = 0,
    ) {
        self::assertValidName($name);
        self::assertMetadataShape($metadata);
    }

    public function isActive(): bool
    {
        return $this->status === ScheduleStatus::Active;
    }

    /** True iff this is a system-wide (non-tenant) schedule. */
    public function isSystem(): bool
    {
        return $this->tenantId === null;
    }

    public function withStatus(ScheduleStatus $newStatus): self
    {
        return new self(
            id:        $this->id,
            name:      $this->name,
            source:    $this->source,
            trigger:   $this->trigger,
            command:   $this->command,
            misfire:   $this->misfire,
            overlap:   $this->overlap,
            timezone:  $this->timezone,
            jitter:    $this->jitter,
            status:    $newStatus,
            tenantId:  $this->tenantId,
            sensitive: $this->sensitive,
            metadata:  $this->metadata,
            version:   $this->version,
        );
    }

    private static function assertValidName(string $name): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Schedule name must match /^[a-z0-9][a-z0-9_-]*$/, got: "%s".',
                    $name,
                )
            );
        }
    }

    /** @param array<mixed, mixed> $metadata */
    private static function assertMetadataShape(array $metadata): void
    {
        foreach ($metadata as $k => $v) {
            if (!is_string($k) || !is_string($v)) {
                throw new InvalidArgumentException(
                    'Schedule metadata must be array<string, string>; non-string key or value found.'
                );
            }
        }
    }
}
