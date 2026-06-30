<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber
 *
 * Uses a TestableHardwareCapabilitiesProber that intercepts exec() calls
 * to validate probe logic paths without requiring FFmpeg on the system.
 */
final class HardwareCapabilitiesProberTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    //
    // Explicit encoder configuration
    //

    public function testExplicitSoftwareEncoderReturnsSoftwareProfile(): void
    {
        $prober = TestableHardwareCapabilitiesProber::create(
            logger: $this->logger,
            preferredEncoder: 'libx265',
        );
        $prober->boot();

        $profile = $prober->getProfile();
        $this->assertSame(HardwareAccelerator::None, $profile->accelerator);
        $this->assertSame('libx265', $profile->encoder);
        $this->assertFalse($profile->isHardware());
    }

    public function testExplicitHardwareEncoderReturnsHardwareProfile(): void
    {
        $prober = TestableHardwareCapabilitiesProber::create(
            logger: $this->logger,
            preferredEncoder: 'hevc_nvenc',
            execResults: [
                // validateEncoder: hwaccel + nullsrc test encode
                new ExecResult('', 0),
            ],
        );
        $prober->boot();

        $profile = $prober->getProfile();
        $this->assertSame(HardwareAccelerator::Nvenc, $profile->accelerator);
        $this->assertSame('hevc_nvenc', $profile->encoder);
        $this->assertTrue($profile->isHardware());
    }

    public function testExplicitHardwareEncoderFallsBackOnValidationFailure(): void
    {
        $prober = TestableHardwareCapabilitiesProber::create(
            logger: $this->logger,
            preferredEncoder: 'hevc_nvenc',
            execResults: [
                // validateEncoder: test encode fails
                new ExecResult('Error: encoder not found', 1),
            ],
        );
        $prober->boot();

        $profile = $prober->getProfile();
        $this->assertSame(HardwareAccelerator::None, $profile->accelerator);
        $this->assertSame('hevc_nvenc', $profile->encoder);
        $this->assertFalse($profile->isHardware());
    }

    public function testExplicitEncoderWithDevicePath(): void
    {
        $prober = TestableHardwareCapabilitiesProber::create(
            logger: $this->logger,
            preferredEncoder: 'hevc_vaapi',
            hwAccelDevice: '/dev/dri/renderD128',
            execResults: [
                // validateEncoder for VAAPI (needs init_hw_device + hwupload)
                new ExecResult('', 0),
            ],
        );
        $prober->boot();

        $profile = $prober->getProfile();
        $this->assertSame(HardwareAccelerator::Vaapi, $profile->accelerator);
        $this->assertSame('hevc_vaapi', $profile->encoder);
        $this->assertSame('/dev/dri/renderD128', $profile->hwaccelDevice);
    }

    //
    // Auto-detect: probeHwaccels + probeEncoders (using callback for flexibility)
    //

    public function testAutoDetectNvencWhenAvailable(): void
    {
        $prober = $this->createAutoDetectProber(
            hwaccels: "Hardware acceleration methods:\ncuda\nvaapi\n",
            encoders: " V..... hevc_nvenc              NVIDIA NVENC hevc encoder\n V..... hevc_vaapi              VAAPI hevc encoder\n",
            validationResults: ['hevc_nvenc' => true],
        );
        $prober->boot();

        $profile = $prober->getProfile();
        $this->assertSame(HardwareAccelerator::Nvenc, $profile->accelerator);
        $this->assertSame('hevc_nvenc', $profile->encoder);
    }

    public function testAutoDetectFallsThroughPriorityOrder(): void
    {
        // Only VAAPI hwaccel available, Nvenc absent
        $prober = $this->createAutoDetectProber(
            hwaccels: "Hardware acceleration methods:\nvaapi\n",
            encoders: " V..... hevc_vaapi              VAAPI hevc encoder\n",
            validationResults: ['hevc_vaapi' => true],
        );
        $prober->boot();

        $profile = $prober->getProfile();
        $this->assertSame(HardwareAccelerator::Vaapi, $profile->accelerator);
        $this->assertSame('hevc_vaapi', $profile->encoder);
    }

    public function testAutoDetectFallsBackToSoftwareWhenNothingAvailable(): void
    {
        $prober = $this->createAutoDetectProber(
            hwaccels: "Hardware acceleration methods:\n",
            encoders: "",
            validationResults: [],
        );
        $prober->boot();

        $profile = $prober->getProfile();
        $this->assertSame(HardwareAccelerator::None, $profile->accelerator);
        $this->assertSame('libx265', $profile->encoder);
    }

    public function testAutoDetectSkipsEncoderWhenValidationFails(): void
    {
        $prober = $this->createAutoDetectProber(
            hwaccels: "Hardware acceleration methods:\ncuda\nvaapi\n",
            encoders: " V..... hevc_nvenc              NVIDIA NVENC hevc encoder\n V..... hevc_vaapi              VAAPI hevc encoder\n",
            validationResults: ['hevc_nvenc' => false, 'hevc_vaapi' => true],
        );
        $prober->boot();

        $profile = $prober->getProfile();
        $this->assertSame(HardwareAccelerator::Vaapi, $profile->accelerator);
        $this->assertSame('hevc_vaapi', $profile->encoder);
    }

    public function testAutoDetectVideoToolboxSkippedDueToEmptyHwaccelMethod(): void
    {
        $prober = $this->createAutoDetectProber(
            hwaccels: "Hardware acceleration methods:\nvideotoolbox\n",
            encoders: " V..... hevc_videotoolbox       VideoToolbox HEVC encoder\n",
            validationResults: [],
        );
        $prober->boot();

        // VideoToolbox.ffmpegHwaccelMethod() returns '' → won't match in hwaccels list
        $profile = $prober->getProfile();
        $this->assertSame(HardwareAccelerator::None, $profile->accelerator);
    }

    public function testAutoDetectQsv(): void
    {
        $prober = $this->createAutoDetectProber(
            hwaccels: "Hardware acceleration methods:\nqsv\n",
            encoders: " V..... hevc_qsv                Intel QSV HEVC encoder\n",
            validationResults: ['hevc_qsv' => true],
        );
        $prober->boot();

        $profile = $prober->getProfile();
        $this->assertSame(HardwareAccelerator::Qsv, $profile->accelerator);
        $this->assertSame('hevc_qsv', $profile->encoder);
    }

    //
    // Boot behavior
    //

    public function testBootIsIdempotent(): void
    {
        $execCallCount = 0;
        $prober = TestableHardwareCapabilitiesProber::createWithCallback(
            logger: $this->logger,
            preferredEncoder: 'auto',
            callback: function (string $cmd) use (&$execCallCount): ExecResult {
                ++$execCallCount;
                if (str_contains($cmd, '-hwaccels')) {
                    return new ExecResult("cuda\n", 0);
                }
                if (str_contains($cmd, '-encoders')) {
                    return new ExecResult(" V..... hevc_nvenc              NVIDIA NVENC\n", 0);
                }
                return new ExecResult('', 0);
            },
        );

        $prober->boot();
        $firstProfile = $prober->getProfile();

        $prober->boot(); // Should not re-probe
        $secondProfile = $prober->getProfile();

        $this->assertSame(3, $execCallCount); // hwaccels + encoders + validate
        $this->assertTrue($firstProfile->equals($secondProfile));
    }

    public function testGetProfileThrowsBeforeBoot(): void
    {
        $prober = TestableHardwareCapabilitiesProber::create(
            logger: $this->logger,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not booted');
        $prober->getProfile();
    }

    //
    // Bitrate multiplier
    //

    public function testBitrateMultiplierFromConstructor(): void
    {
        $prober = TestableHardwareCapabilitiesProber::create(
            logger: $this->logger,
            preferredEncoder: 'libx265',
            bitrateMultiplier: 1.5,
        );
        $prober->boot();

        $this->assertSame(1.5, $prober->getBitrateMultiplier());
    }

    public function testDefaultBitrateMultiplierIsOne(): void
    {
        $prober = TestableHardwareCapabilitiesProber::create(
            logger: $this->logger,
            preferredEncoder: 'libx265',
        );
        $prober->boot();

        $this->assertSame(1.0, $prober->getBitrateMultiplier());
    }

    //
    // Encoder validation command structure
    //

    public function testValidationCommandIncludesInitHwDeviceForVaapi(): void
    {
        $capturedCmd = '';
        $prober = TestableHardwareCapabilitiesProber::createWithCallback(
            logger: $this->logger,
            preferredEncoder: 'hevc_vaapi',
            hwAccelDevice: '/dev/dri/renderD128',
            callback: function (string $cmd) use (&$capturedCmd): ExecResult {
                $capturedCmd = $cmd;
                return new ExecResult('', 0);
            },
        );
        $prober->boot();

        $this->assertStringContainsString('-init_hw_device vaapi=vaapi_0', $capturedCmd);
        $this->assertStringContainsString('hwupload', $capturedCmd);
        $this->assertStringContainsString('hevc_vaapi', $capturedCmd);
    }

    public function testValidationCommandNoInitDeviceForNvenc(): void
    {
        $capturedCmd = '';
        $prober = TestableHardwareCapabilitiesProber::createWithCallback(
            logger: $this->logger,
            preferredEncoder: 'hevc_nvenc',
            callback: function (string $cmd) use (&$capturedCmd): ExecResult {
                $capturedCmd = $cmd;
                return new ExecResult('', 0);
            },
        );
        $prober->boot();

        $this->assertStringNotContainsString('-init_hw_device', $capturedCmd);
        $this->assertStringContainsString('hevc_nvenc', $capturedCmd);
    }

    /**
     * Helper: create a prober in auto-detect mode with simulated FFmpeg output.
     *
     * @param array<string, bool> $validationResults encoder name → whether validation passes
     */
    private function createAutoDetectProber(
        string $hwaccels,
        string $encoders,
        array $validationResults,
    ): TestableHardwareCapabilitiesProber {
        return TestableHardwareCapabilitiesProber::createWithCallback(
            logger: $this->logger,
            preferredEncoder: 'auto',
            callback: function (string $cmd) use ($hwaccels, $encoders, $validationResults): ExecResult {
                if (str_contains($cmd, '-hwaccels')) {
                    return new ExecResult($hwaccels, 0);
                }
                if (str_contains($cmd, '-encoders')) {
                    return new ExecResult($encoders, 0);
                }
                // Validation: extract encoder name from command
                foreach ($validationResults as $encoder => $passes) {
                    if (str_contains($cmd, $encoder)) {
                        return new ExecResult('', $passes ? 0 : 1);
                    }
                }

                return new ExecResult('', 1);
            },
        );
    }
}

/**
 * Simple DTO for exec() return values in tests.
 */
final readonly class ExecResult
{
    public function __construct(
        public string $output,
        public int $exitCode,
    ) {
    }
}

/**
 * Testable subclass that intercepts exec() calls with a queue of pre-configured results.
 */
class TestableHardwareCapabilitiesProber extends HardwareCapabilitiesProber
{
    /** @var list<ExecResult> */
    private array $execQueue = [];

    /** @var \Closure(string): ExecResult|null */
    private ?\Closure $callback = null;

    /**
     * @param list<ExecResult> $execResults
     */
    public static function create(
        LoggerInterface $logger,
        string $preferredEncoder = '',
        string $hwAccelDevice = '',
        float $bitrateMultiplier = 1.0,
        array $execResults = [],
    ): self {
        $instance = new self($logger, '/usr/bin/ffmpeg', $preferredEncoder, $hwAccelDevice, $bitrateMultiplier);
        $instance->execQueue = $execResults;

        return $instance;
    }

    /**
     * @param \Closure(string): ExecResult|null $callback
     */
    public static function createWithCallback(
        LoggerInterface $logger,
        string $preferredEncoder = '',
        string $hwAccelDevice = '',
        float $bitrateMultiplier = 1.0,
        ?\Closure $callback = null,
    ): self {
        $instance = new self($logger, '/usr/bin/ffmpeg', $preferredEncoder, $hwAccelDevice, $bitrateMultiplier);
        $instance->callback = $callback;

        return $instance;
    }

    protected function exec(string $cmd): string
    {
        if ($this->callback !== null) {
            $result = ($this->callback)($cmd);
            $this->lastExitCode = $result->exitCode;

            return $result->output;
        }

        if ($this->execQueue === []) {
            throw new \LogicException(sprintf('Unexpected exec() call (no more queued results): %s', $cmd));
        }

        $result = array_shift($this->execQueue);
        $this->lastExitCode = $result->exitCode;

        return $result->output;
    }
}
