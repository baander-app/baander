<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Infrastructure\FFmpeg\EncoderProfileFingerprintAdapter;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use PHPUnit\Framework\TestCase;

final class EncoderProfileFingerprintAdapterTest extends TestCase
{
    public function testFingerprintPreservesThePersistedProfileName(): void
    {
        $profile = EncoderProfile::software('libx265');
        $prober = $this->createMock(HardwareCapabilitiesProber::class);
        $prober->expects(self::once())->method('getProfile')->willReturn($profile);

        self::assertSame('none/libx265', (new EncoderProfileFingerprintAdapter($prober))->getName());
    }

    public function testUnavailableProfileFailureReachesTheStartupBoundary(): void
    {
        $prober = $this->createStub(HardwareCapabilitiesProber::class);
        $prober->method('getProfile')->willThrowException(new \RuntimeException('profile unavailable'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('profile unavailable');

        (new EncoderProfileFingerprintAdapter($prober))->getName();
    }
}
