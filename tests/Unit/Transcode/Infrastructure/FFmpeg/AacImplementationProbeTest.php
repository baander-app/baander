<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Tests\Support\StubAacImplementationProbe;
use App\Transcode\Domain\ValueObject\AacImplementation;
use App\Transcode\Infrastructure\FFmpeg\AacImplementationProbe;
use App\Transcode\Infrastructure\FFmpeg\AacImplementationUnavailableException;
use PHPUnit\Framework\TestCase;

final class AacImplementationProbeTest extends TestCase
{
    public function testEveryImplementationListedAsEncoderAndDecoderIsAvailable(): void
    {
        $probe = new StubAacImplementationProbe();
        self::assertSame(AacImplementation::cases(), $probe->available());
        foreach (AacImplementation::cases() as $implementation) {
            $probe->assertAvailable($implementation);
        }
    }

    public function testAMissingCodecIsReportedByTheImplementationsLabel(): void
    {
        $probe = new StubAacImplementationProbe(['libfdk_aac', 'aac'], ['libfdk_aac', 'aac']);
        self::assertSame([AacImplementation::FdkAac, AacImplementation::Ffmpeg], $probe->available());
        self::assertFalse($probe->isAvailable(AacImplementation::Baac));
        $this->expectException(AacImplementationUnavailableException::class);
        $this->expectExceptionMessage('AAC implementation "bAAC" is not available in this FFmpeg build');
        $probe->assertAvailable(AacImplementation::Baac);
    }

    public function testAnImplementationNeedsBothDirections(): void
    {
        $probe = new StubAacImplementationProbe(['libbaac', 'aac'], ['aac']);
        self::assertFalse($probe->isAvailable(AacImplementation::Baac), 'an encoder without its decoder is unavailable');
        self::assertTrue($probe->offersEncoder(AacImplementation::Baac));
        self::assertFalse($probe->offersDecoder(AacImplementation::Baac));
        self::assertTrue($probe->isAvailable(AacImplementation::Ffmpeg));
    }

    public function testAFailedListingRaisesAndIsProbedAgain(): void
    {
        $probe = new class extends AacImplementationProbe {
            public int $runs = 0;

            public function __construct()
            {
                parent::__construct('/usr/bin/ffmpeg');
            }

            protected function exec(array $command): string
            {
                ++$this->runs;

                return $this->runs === 1 ? '' : " A....D libbaac               bAAC (codec aac)\n";
            }
        };
        try {
            $probe->isAvailable(AacImplementation::Baac);
            self::fail('an empty listing must raise');
        } catch (\RuntimeException $error) {
            self::assertSame('FFmpeg at /usr/bin/ffmpeg listed no encoders', $error->getMessage());
        }
        self::assertTrue($probe->isAvailable(AacImplementation::Baac), 'the failure was not cached');
        self::assertSame(3, $probe->runs);
    }
}
