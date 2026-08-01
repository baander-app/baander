<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Domain\ValueObject\SessionState;
use PHPUnit\Framework\TestCase;

class SessionStateTest extends TestCase
{
    public function testStateTransitions(): void
    {
        $this->assertTrue(SessionState::Pending->canTransitionTo(SessionState::Preparing));
        $this->assertTrue(SessionState::Pending->canTransitionTo(SessionState::Cancelled));
        $this->assertFalse(SessionState::Pending->canTransitionTo(SessionState::Active));

        $this->assertTrue(SessionState::Preparing->canTransitionTo(SessionState::Active));
        $this->assertTrue(SessionState::Preparing->canTransitionTo(SessionState::Failed));
        $this->assertFalse(SessionState::Preparing->canTransitionTo(SessionState::Paused));

        $this->assertTrue(SessionState::Active->canTransitionTo(SessionState::Paused));
        $this->assertTrue(SessionState::Active->canTransitionTo(SessionState::Completed));
        $this->assertTrue(SessionState::Active->canTransitionTo(SessionState::Failed));
        $this->assertTrue(SessionState::Active->canTransitionTo(SessionState::Cancelled));

        $this->assertTrue(SessionState::Paused->canTransitionTo(SessionState::Active));
        $this->assertFalse(SessionState::Paused->canTransitionTo(SessionState::Completed));
    }

    public function testTerminalStatesHaveNoTransitions(): void
    {
        foreach ([SessionState::Completed, SessionState::Failed, SessionState::Cancelled] as $state) {
            $this->assertEmpty($state->allowedTransitions(), "{$state->value} should have no transitions");
        }
    }

    public function testEnumValues(): void
    {
        $this->assertSame('pending', SessionState::Pending->value);
        $this->assertSame('preparing', SessionState::Preparing->value);
        $this->assertSame('active', SessionState::Active->value);
        $this->assertSame('paused', SessionState::Paused->value);
        $this->assertSame('completed', SessionState::Completed->value);
        $this->assertSame('failed', SessionState::Failed->value);
        $this->assertSame('cancelled', SessionState::Cancelled->value);
    }
}
