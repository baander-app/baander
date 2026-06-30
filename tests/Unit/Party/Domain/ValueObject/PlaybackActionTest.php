<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Domain\ValueObject;

use App\Party\Domain\ValueObject\PlaybackAction;
use PHPUnit\Framework\TestCase;
use ValueError;

final class PlaybackActionTest extends TestCase
{
    public function testPlayValue(): void
    {
        $this->assertSame('play', PlaybackAction::Play->value);
    }

    public function testPlayLabel(): void
    {
        $this->assertSame('Play', PlaybackAction::Play->label());
    }

    public function testPauseValue(): void
    {
        $this->assertSame('pause', PlaybackAction::Pause->value);
    }

    public function testPauseLabel(): void
    {
        $this->assertSame('Pause', PlaybackAction::Pause->label());
    }

    public function testSeekValue(): void
    {
        $this->assertSame('seek', PlaybackAction::Seek->value);
    }

    public function testSeekLabel(): void
    {
        $this->assertSame('Seek', PlaybackAction::Seek->label());
    }

    public function testJoinValue(): void
    {
        $this->assertSame('join', PlaybackAction::Join->value);
    }

    public function testJoinLabel(): void
    {
        $this->assertSame('Join', PlaybackAction::Join->label());
    }

    public function testLeaveValue(): void
    {
        $this->assertSame('leave', PlaybackAction::Leave->value);
    }

    public function testLeaveLabel(): void
    {
        $this->assertSame('Leave', PlaybackAction::Leave->label());
    }

    public function testCasesCount(): void
    {
        $this->assertCount(5, PlaybackAction::cases());
    }

    public function testCasesContainAllActions(): void
    {
        $cases = PlaybackAction::cases();

        $this->assertContains(PlaybackAction::Play, $cases);
        $this->assertContains(PlaybackAction::Pause, $cases);
        $this->assertContains(PlaybackAction::Seek, $cases);
        $this->assertContains(PlaybackAction::Join, $cases);
        $this->assertContains(PlaybackAction::Leave, $cases);
    }

    public function testFromValidStringReturnsSeek(): void
    {
        $this->assertSame(PlaybackAction::Seek, PlaybackAction::from('seek'));
    }

    public function testFromInvalidStringThrowsValueError(): void
    {
        $this->expectException(ValueError::class);

        PlaybackAction::from('invalid_action');
    }
}
