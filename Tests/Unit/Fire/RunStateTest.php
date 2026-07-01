<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Fire;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\RunState;

final class RunStateTest extends TestCase
{
    public function test_all_cases_exist(): void
    {
        self::assertSame('dispatched', RunState::Dispatched->value);
        self::assertSame('completed',  RunState::Completed->value);
        self::assertSame('failed',     RunState::Failed->value);
    }

    public function test_from_string_works_for_all_cases(): void
    {
        self::assertSame(RunState::Dispatched, RunState::from('dispatched'));
        self::assertSame(RunState::Completed,  RunState::from('completed'));
        self::assertSame(RunState::Failed,     RunState::from('failed'));
    }

    public function test_from_invalid_string_throws(): void
    {
        $this->expectException(\ValueError::class);

        RunState::from('unknown');
    }

    public function test_dispatched_is_not_terminal(): void
    {
        self::assertFalse(RunState::Dispatched->isTerminal());
    }

    public function test_completed_is_terminal(): void
    {
        self::assertTrue(RunState::Completed->isTerminal());
    }

    public function test_failed_is_terminal(): void
    {
        self::assertTrue(RunState::Failed->isTerminal());
    }

    public function test_dispatched_allows_transition_to_completed(): void
    {
        self::assertContains(RunState::Completed, RunState::Dispatched->allowedTransitions());
    }

    public function test_dispatched_allows_transition_to_failed(): void
    {
        self::assertContains(RunState::Failed, RunState::Dispatched->allowedTransitions());
    }

    public function test_completed_has_no_allowed_transitions(): void
    {
        self::assertSame([], RunState::Completed->allowedTransitions());
    }

    public function test_failed_has_no_allowed_transitions(): void
    {
        self::assertSame([], RunState::Failed->allowedTransitions());
    }

    public function test_can_transition_to_returns_true_for_valid(): void
    {
        self::assertTrue(RunState::Dispatched->canTransitionTo(RunState::Completed));
        self::assertTrue(RunState::Dispatched->canTransitionTo(RunState::Failed));
    }

    public function test_can_transition_to_returns_false_for_invalid(): void
    {
        self::assertFalse(RunState::Completed->canTransitionTo(RunState::Failed));
        self::assertFalse(RunState::Completed->canTransitionTo(RunState::Completed));
        self::assertFalse(RunState::Failed->canTransitionTo(RunState::Completed));
        self::assertFalse(RunState::Failed->canTransitionTo(RunState::Failed));
        self::assertFalse(RunState::Dispatched->canTransitionTo(RunState::Dispatched));
    }
}
