<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Schedule;

use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;

final class ScheduleTest extends TestCase
{
    public function test_valid_kebab_name_accepted(): void
    {
        $s = $this->make(name: 'nightly-report');

        self::assertSame('nightly-report', $s->name);
    }

    public function test_valid_snake_name_accepted(): void
    {
        self::assertSame('daily_sync', $this->make(name: 'daily_sync')->name);
    }

    public function test_single_char_name_accepted(): void
    {
        self::assertSame('a', $this->make(name: 'a')->name);
    }

    public function test_name_with_digits_accepted(): void
    {
        self::assertSame('a1', $this->make(name: 'a1')->name);
    }

    public function test_name_with_spaces_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make(name: 'My Schedule');
    }

    public function test_uppercase_name_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make(name: 'UPPER');
    }

    public function test_name_starting_with_dash_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make(name: '-start');
    }

    public function test_empty_name_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make(name: '');
    }

    public function test_name_starting_with_underscore_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make(name: '_start');
    }

    public function test_is_active_returns_true_for_active_status(): void
    {
        self::assertTrue($this->make(status: ScheduleStatus::Active)->isActive());
    }

    public function test_is_active_returns_false_for_paused(): void
    {
        self::assertFalse($this->make(status: ScheduleStatus::Paused)->isActive());
    }

    public function test_is_active_returns_false_for_disabled(): void
    {
        self::assertFalse($this->make(status: ScheduleStatus::Disabled)->isActive());
    }

    public function test_with_status_returns_new_instance(): void
    {
        $original = $this->make(status: ScheduleStatus::Active);
        $paused   = $original->withStatus(ScheduleStatus::Paused);

        self::assertNotSame($original, $paused);
        self::assertSame(ScheduleStatus::Active, $original->status);
        self::assertSame(ScheduleStatus::Paused, $paused->status);
    }

    public function test_with_status_preserves_all_other_fields(): void
    {
        $original = $this->make(name: 'preserve-me', status: ScheduleStatus::Active);
        $paused   = $original->withStatus(ScheduleStatus::Paused);

        self::assertSame($original->id, $paused->id);
        self::assertSame($original->name, $paused->name);
        self::assertSame($original->source, $paused->source);
        self::assertSame($original->misfire, $paused->misfire);
        self::assertSame($original->overlap, $paused->overlap);
        self::assertSame($original->sensitive, $paused->sensitive);
        self::assertSame($original->tenantId, $paused->tenantId);
    }

    public function test_is_system_when_tenant_id_is_null(): void
    {
        self::assertTrue($this->make(tenantId: null)->isSystem());
    }

    public function test_is_not_system_when_tenant_id_is_set(): void
    {
        self::assertFalse($this->make(tenantId: 'tenant-1')->isSystem());
    }

    public function test_metadata_string_string_accepted(): void
    {
        $s = $this->make(metadata: ['env' => 'prod', 'team' => 'billing']);

        self::assertSame(['env' => 'prod', 'team' => 'billing'], $s->metadata);
    }

    public function test_empty_metadata_accepted(): void
    {
        $s = $this->make(metadata: []);

        self::assertSame([], $s->metadata);
    }

    public function test_metadata_with_integer_value_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore-next-line
        $this->make(metadata: ['key' => 123]);
    }

    public function test_metadata_with_integer_key_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore-next-line
        $this->make(metadata: [0 => 'value']);
    }

    public function test_sensitive_flag_stored_as_false_by_default(): void
    {
        self::assertFalse($this->make()->sensitive);
    }

    public function test_sensitive_flag_stored_as_true(): void
    {
        self::assertTrue($this->make(sensitive: true)->sensitive);
    }

    private function make(
        string         $name      = 'test-schedule',
        ScheduleStatus $status    = ScheduleStatus::Active,
        ?string        $tenantId  = null,
        bool           $sensitive = false,
        array          $metadata  = [],
    ): Schedule {
        return new Schedule(
            id:        ScheduleId::generate(),
            name:      $name,
            source:    ScheduleSource::Static,
            trigger:   new IntervalTrigger(60),
            command:   new CommandSpec('App\\Command\\FakeCommand'),
            misfire:   MisfirePolicy::skipMissed(),
            overlap:   OverlapPolicy::Skip,
            timezone:  new DateTimeZone('UTC'),
            jitter:    null,
            status:    $status,
            tenantId:  $tenantId,
            sensitive: $sensitive,
            metadata:  $metadata,
        );
    }
}
