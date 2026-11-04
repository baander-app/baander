<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use App\Transcode\Infrastructure\FFmpeg\ProcessExecutor;
use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Shared\Infrastructure\Worker\NativeExecutionHandle;
use PHPUnit\Framework\MockObject\Stub;
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
    private LoggerInterface&Stub $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        ProcessExecutor::configureNativeExecution(null);
    }

    public function testHouseholdNeverRunsStartupEncoderQualification(): void
    {
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $client->expects(self::never())->method('submit');
        ProcessExecutor::configureNativeExecution($client);
        $policy = new \App\Transcode\Application\Service\MediaExecutionPolicy(new \App\Shared\Application\DTO\DeploymentProfile('household'));
        $prober = new HardwareCapabilitiesProber($policy, $this->logger);
        $this->expectException(\App\Transcode\Application\Exception\MediaEncodingProhibitedException::class);
        $prober->probeForWorker();
    }

    public function testRemoteStartupQualifiesSoftwareInsteadOfConfiguredHardware(): void
    {
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $client->expects(self::once())->method('submit')->with(self::callback(static function (array $argv): bool {
            return in_array('libx265', $argv, true) && !in_array('hevc_nvenc', $argv, true) && in_array('lavfi', $argv, true);
        }), 30)->willReturn(new NativeExecutionHandle(str_repeat('a', 64), 1));
        $client->method('inspect')->willReturn(['state' => 'exited', 'exitCode' => 0, 'stdout' => '', 'stderr' => '']);
        $client->method('release')->willReturn(true);
        ProcessExecutor::configureNativeExecution($client);
        $prober = new HardwareCapabilitiesProber(new \App\Transcode\Application\Service\MediaExecutionPolicy(new \App\Shared\Application\DTO\DeploymentProfile('full')), $this->logger, preferredEncoder: 'hevc_nvenc');
        $profile = $prober->probeForWorker();
        self::assertSame('libx265', $profile->encoder);
        self::assertFalse($profile->isHardware());
        self::assertSame($profile, $prober->probeForWorker());
    }

    public function testRemoteStartupFailsWhenSoftwareQualificationFails(): void
    {
        $client = $this->createStub(NativeExecutionClientInterface::class);
        $client->method('submit')->willReturn(new NativeExecutionHandle(str_repeat('a', 64), 1));
        $client->method('inspect')->willReturn(['state' => 'exited', 'exitCode' => 1, 'stderr' => 'encoder missing']);
        $client->method('release')->willReturn(true);
        ProcessExecutor::configureNativeExecution($client);
        $this->expectException(\RuntimeException::class);
        (new HardwareCapabilitiesProber(new \App\Transcode\Application\Service\MediaExecutionPolicy(new \App\Shared\Application\DTO\DeploymentProfile('full')), $this->logger))->probeForWorker();
    }

    public function testRemoteStartupQualifiesTheConfiguredHardwareEncoderOnItsDevice(): void
    {
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $client->expects(self::once())->method('submit')->with(self::callback(static function (array $argv): bool {
            return in_array('hevc_vaapi', $argv, true) && in_array('vaapi=vaapi_0:/dev/dri/renderD129', $argv, true)
                && in_array('format=nv12,hwupload', $argv, true) && !in_array('libx265', $argv, true)
                // Hardware encoders refuse frames below their minimum size; RDNA4 HEVC needs 384 pixels across.
                && in_array('nullsrc=s=640x360:d=0.1', $argv, true);
        }), 30)->willReturn(new NativeExecutionHandle(str_repeat('a', 64), 1));
        $client->method('inspect')->willReturn(['state' => 'exited', 'exitCode' => 0, 'stdout' => '', 'stderr' => '']);
        $client->method('release')->willReturn(true);
        ProcessExecutor::configureNativeExecution($client);
        $profile = $this->configuredProber('vaapi', '/dev/dri/renderD129')->probeForWorker();

        self::assertSame(HardwareAccelerator::Vaapi, $profile->accelerator);
        self::assertSame('hevc_vaapi', $profile->encoder);
        self::assertSame('/dev/dri/renderD129', $profile->hwaccelDevice);
        self::assertSame(['-hwaccel', 'vaapi', '-hwaccel_device', '/dev/dri/renderD129', '-hwaccel_output_format', 'vaapi'], \App\Transcode\Infrastructure\FFmpeg\FFmpegArguments::input($profile));
    }

    public function testConfiguredH264EncoderSettingSelectsTheAcceleratorsH264Encoder(): void
    {
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $client->expects(self::once())->method('submit')->with(self::callback(static fn (array $argv): bool => in_array('h264_vaapi', $argv, true)), 30)
            ->willReturn(new NativeExecutionHandle(str_repeat('a', 64), 1));
        $client->method('inspect')->willReturn(['state' => 'exited', 'exitCode' => 0, 'stdout' => '', 'stderr' => '']);
        $client->method('release')->willReturn(true);
        ProcessExecutor::configureNativeExecution($client);

        self::assertSame('h264_vaapi', $this->configuredProber('vaapi', '/dev/dri/renderD128', 'h264_vaapi')->probeForWorker()->encoder);
    }

    public function testConfiguredNvencOpensNoDevice(): void
    {
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $client->expects(self::once())->method('submit')->with(self::callback(static fn (array $argv): bool => in_array('hevc_nvenc', $argv, true)
            && !in_array('-init_hw_device', $argv, true)), 30)->willReturn(new NativeExecutionHandle(str_repeat('a', 64), 1));
        $client->method('inspect')->willReturn(['state' => 'exited', 'exitCode' => 0, 'stdout' => '', 'stderr' => '']);
        $client->method('release')->willReturn(true);
        ProcessExecutor::configureNativeExecution($client);

        $profile = $this->configuredProber('nvenc', null)->probeForWorker();
        self::assertSame(HardwareAccelerator::Nvenc, $profile->accelerator);
        self::assertSame('', $profile->hwaccelDevice);
    }

    public function testConfiguredHardwareThatFailsToInitializeStopsStartupWithANamedReason(): void
    {
        $client = $this->createStub(NativeExecutionClientInterface::class);
        $client->method('submit')->willReturn(new NativeExecutionHandle(str_repeat('a', 64), 1));
        $client->method('inspect')->willReturn(['state' => 'exited', 'exitCode' => 187, 'stdout' => '',
            'stderr' => "ffmpeg version 7.1\n[AVHWDeviceContext @ 0x1] No VA display found for device /dev/dri/renderD128.\nDevice creation failed: -22.\nFailed to set value 'vaapi=vaapi_0:/dev/dri/renderD128' for option 'init_hw_device': Invalid argument\n"]);
        $client->method('release')->willReturn(true);
        ProcessExecutor::configureNativeExecution($client);
        $prober = $this->configuredProber('vaapi', '/dev/dri/renderD128');
        try {
            $prober->probeForWorker();
            self::fail('Startup encoded in software after the configured hardware failed.');
        } catch (\App\Transcode\Infrastructure\FFmpeg\HardwareEncoderUnavailable $error) {
            self::assertSame(['hevc_vaapi', '/dev/dri/renderD128'], [$error->encoder, $error->device]);
            self::assertStringContainsString('The configured hardware encoder hevc_vaapi on /dev/dri/renderD128 did not initialize', $error->getMessage());
            self::assertStringContainsString('No VA display found for device /dev/dri/renderD128', $error->getMessage());
            self::assertSame([
                'reason' => 'hardware_encoder_unavailable', 'encoder' => 'hevc_vaapi', 'device' => '/dev/dri/renderD128', 'detail' => $error->getMessage(),
            ], \App\Transcode\Infrastructure\Worker\MediaWorkerFailure::reason($error));
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('have not been probed');
        $prober->getProfile();
    }

    public function testOtherStartupFailuresNameNoReason(): void
    {
        self::assertSame([], \App\Transcode\Infrastructure\Worker\MediaWorkerFailure::reason(new \RuntimeException('postgresql://credential:private-secret@baander.app')));
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
        $prober->probeForWorker();

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
        $prober->probeForWorker();

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
        $prober->probeForWorker();

        $profile = $prober->getProfile();
        $this->assertSame(HardwareAccelerator::None, $profile->accelerator);
        $this->assertSame('libx265', $profile->encoder);
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
        $prober->probeForWorker();

        $profile = $prober->getProfile();
        $this->assertSame(HardwareAccelerator::Vaapi, $profile->accelerator);
        $this->assertSame('hevc_vaapi', $profile->encoder);
        $this->assertSame('/dev/dri/renderD128', $profile->hwaccelDevice);
    }

    public function testWorkerProbePassesDeviceAsOneArgumentAndBoundsCpuThreads(): void
    {
        $directory = sys_get_temp_dir() . '/baander-probe-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $executable = $directory . '/ffmpeg with spaces';
        $arguments = $directory . '/argv.json';
        file_put_contents($executable, '#!' . PHP_BINARY . "\n<?php\nfile_put_contents(__DIR__ . '/argv.json', json_encode(['argv' => \$argv, 'threads' => getenv('OMP_NUM_THREADS')]));\n");
        chmod($executable, 0700);
        try {
            $prober = new HardwareCapabilitiesProber(new \App\Transcode\Application\Service\MediaExecutionPolicy(new \App\Shared\Application\DTO\DeploymentProfile('full')), $this->logger, $executable, 'hevc_vaapi', '/dev/dri/device with spaces');
            $profile = $prober->probeForWorker();
            self::assertTrue($profile->isHardware());
            $captured = json_decode((string) file_get_contents($arguments), true, flags: JSON_THROW_ON_ERROR);
            self::assertContains('vaapi=vaapi_0:/dev/dri/device with spaces', $captured['argv']);
            self::assertSame('1', $captured['threads']);
            foreach (['-threads', '-filter_threads', '-filter_complex_threads'] as $flag) {
                self::assertSame('1', $captured['argv'][array_search($flag, $captured['argv'], true) + 1]);
            }
        } finally {
            if (is_file($arguments)) {
                unlink($arguments);
            }
            unlink($executable);
            rmdir($directory);
        }
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
        $prober->probeForWorker();

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
        $prober->probeForWorker();

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
        $prober->probeForWorker();

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
        $prober->probeForWorker();

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
        $prober->probeForWorker();

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
        $prober->probeForWorker();

        $profile = $prober->getProfile();
        $this->assertSame(HardwareAccelerator::Qsv, $profile->accelerator);
        $this->assertSame('hevc_qsv', $profile->encoder);
    }

    //
    // Boot behavior
    //

    public function testWorkerProbeIsIdempotent(): void
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

        $prober->probeForWorker();
        $firstProfile = $prober->getProfile();

        $prober->probeForWorker(); // Should not re-probe
        $secondProfile = $prober->getProfile();

        $this->assertSame(3, $execCallCount); // hwaccels + encoders + validate
        $this->assertTrue($firstProfile->equals($secondProfile));
    }

    public function testGetProfileRequiresAnExplicitWorkerProbe(): void
    {
        $prober = TestableHardwareCapabilitiesProber::create(logger: $this->logger);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Worker hardware capabilities have not been probed');
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
        $prober->probeForWorker();

        $this->assertSame(1.5, $prober->getBitrateMultiplier());
    }

    public function testDefaultBitrateMultiplierIsOne(): void
    {
        $prober = TestableHardwareCapabilitiesProber::create(
            logger: $this->logger,
            preferredEncoder: 'libx265',
        );
        $prober->probeForWorker();

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
        $prober->probeForWorker();

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
        $prober->probeForWorker();

        $this->assertStringNotContainsString('-init_hw_device', $capturedCmd);
        $this->assertStringContainsString('hevc_nvenc', $capturedCmd);
    }

    private function configuredProber(string $accelerator, ?string $device, string $preferredEncoder = ''): HardwareCapabilitiesProber
    {
        $profile = new \App\Shared\Application\DTO\DeploymentProfile('full', 8, 12, new \App\Shared\Application\DTO\HardwareEncoder($accelerator, $device));

        return new HardwareCapabilitiesProber(new \App\Transcode\Application\Service\MediaExecutionPolicy($profile), $this->logger,
            preferredEncoder: $preferredEncoder, deploymentProfile: $profile);
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
        $instance = new self(new \App\Transcode\Application\Service\MediaExecutionPolicy(new \App\Shared\Application\DTO\DeploymentProfile('full')), $logger, '/usr/bin/ffmpeg', $preferredEncoder, $hwAccelDevice, $bitrateMultiplier);
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
        $instance = new self(new \App\Transcode\Application\Service\MediaExecutionPolicy(new \App\Shared\Application\DTO\DeploymentProfile('full')), $logger, '/usr/bin/ffmpeg', $preferredEncoder, $hwAccelDevice, $bitrateMultiplier);
        $instance->callback = $callback;

        return $instance;
    }

    protected function exec(array $command): string
    {
        $cmd = implode(' ', $command);
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
