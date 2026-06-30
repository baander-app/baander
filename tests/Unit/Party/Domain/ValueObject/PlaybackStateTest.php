<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Domain\ValueObject;

use App\Party\Domain\ValueObject\PlaybackState;
use PHPUnit\Framework\TestCase;
use ValueError;

final class PlaybackStateTest extends TestCase
{
    public function testPlayingValue(): void
    {
        $this->assertSame('playing', PlaybackState::Playing->value);
    }

    public function testPlayingLabel(): void
    {
        $this->assertSame('Playing', PlaybackState::Playing->label());
    }

    public function testPausedValue(): void
    {
        $this->assertSame('paused', PlaybackState::Paused->value);
    }

    public function testPausedLabel(): void
    {
        $this->assertSame('Paused', PlaybackState::Paused->label());
    }

    public function testStoppedValue(): void
    {
        $this->assertSame('stopped', PlaybackState::Stopped->value);
    }

    public function testStoppedLabel(): void
    {
        $this->assertSame('Stopped', PlaybackState::Stopped->label());
    }

    public function testCasesCount(): void
    {
        $this->assertCount(3, PlaybackState::cases());
    }

    public function testCasesContainAllStates(): void
    {
        $cases = PlaybackState::cases();

        $this->assertContains(PlaybackState::Playing, $cases);
        $this->assertContains(PlaybackState::Paused, $cases);
        $this->assertContains(PlaybackState::Stopped, $cases);
    }

    public function testFromValidStringReturnsPaused(): void
    {
        $this->assertSame(PlaybackState::Paused, PlaybackState::from('paused'));
    }

    public function testFromInvalidStringThrowsValueError(): void
    {
        $this->expectException(ValueError::class);

        PlaybackState::from('invalid_state');
    }
}
