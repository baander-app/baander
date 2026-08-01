<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Domain\ValueObject\LoudnessStandard;
use PHPUnit\Framework\TestCase;

class LoudnessStandardTest extends TestCase
{
    public function testEnumHasFiveCases(): void
    {
        $cases = LoudnessStandard::cases();
        $this->assertCount(5, $cases);
    }

    public function testEnumValues(): void
    {
        $this->assertSame('ebu_r128', LoudnessStandard::EbuR128->value);
        $this->assertSame('atsc_a85', LoudnessStandard::AtscA85->value);
        $this->assertSame('streaming', LoudnessStandard::Streaming->value);
        $this->assertSame('mobile', LoudnessStandard::Mobile->value);
        $this->assertSame('dialogue', LoudnessStandard::Dialogue->value);
    }

    public function testTargetLufs(): void
    {
        $this->assertSame(-23.0, LoudnessStandard::EbuR128->targetLufs());
        $this->assertSame(-24.0, LoudnessStandard::AtscA85->targetLufs());
        $this->assertSame(-16.0, LoudnessStandard::Streaming->targetLufs());
        $this->assertSame(-14.0, LoudnessStandard::Mobile->targetLufs());
        $this->assertSame(-20.0, LoudnessStandard::Dialogue->targetLufs());
    }
}
