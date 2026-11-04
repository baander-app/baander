<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\HardwareEncoder;
use App\Transcode\Application\DTO\MediaOperation;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\QualityTier;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Probes hardware only when explicitly called by the admitted worker runtime.
 *
 * Runs `ffmpeg -hwaccels` and `ffmpeg -encoders` to detect GPU capabilities,
 * then validates each detected accelerator with a test encode.
 * Produces an EncoderProfile for the best available hardware, or falls back to software.
 *
 * Under native execution the deployment profile decides: a configured hardware encoder must pass
 * its test encode on the configured device or startup fails, and without one the software encoder
 * is qualified.
 */
class HardwareCapabilitiesProber
{
    private ?EncoderProfile $profile = null;

    public function __construct(
        private readonly MediaExecutionPolicy $executionPolicy,
        private readonly LoggerInterface $logger,
        private readonly string $ffmpegPath = '/usr/bin/ffmpeg',
        private readonly string $preferredEncoder = '',
        private readonly string $hwAccelDevice = '',
        private readonly float $bitrateMultiplier = 1.0,
        private readonly ?DeploymentProfile $deploymentProfile = null,
    ) {
    }

    public function probeForWorker(): EncoderProfile
    {
        $this->executionPolicy->assertAllowed(MediaOperation::VideoEncode);
        if ($this->profile !== null) {
            return $this->profile;
        }
        if (ProcessExecutor::usesNativeExecution()) {
            if ($this->deploymentProfile?->hardware !== null) {
                return $this->profile = $this->qualifyConfiguredHardware($this->deploymentProfile->hardware);
            }
            $encoder = in_array($this->preferredEncoder, ['libx264', 'libx265', 'libsvtav1'], true) ? $this->preferredEncoder : 'libx265';
            if (!$this->validateEncoder(HardwareAccelerator::None, $encoder)) {
                throw new RuntimeException('The execution runtime failed software encoder qualification.');
            }
            return $this->profile = EncoderProfile::software($encoder);
        }
        return $this->profile = $this->probe();
    }

    /**
     * The configured accelerator's HEVC encoder, or the H.264 one when the encoder setting names
     * it, must complete a test encode on the configured device.
     *
     * @throws HardwareEncoderUnavailable when the test encode fails
     */
    private function qualifyConfiguredHardware(HardwareEncoder $hardware): EncoderProfile
    {
        $accelerator = HardwareAccelerator::from($hardware->accelerator);
        $encoder = in_array($this->preferredEncoder, [$accelerator->hevcEncoder(), $accelerator->h264Encoder()], true)
            ? $this->preferredEncoder
            : $accelerator->hevcEncoder();
        $device = $hardware->device ?? '';
        if (!$this->validateEncoder($accelerator, $encoder, $device)) {
            throw new HardwareEncoderUnavailable($encoder, $device, $this->lastDiagnostic);
        }
        $this->logger->info('Using configured hardware encoder', ['encoder' => $encoder, 'accelerator' => $accelerator->value, 'device' => $device]);

        return EncoderProfile::fromEncoderName($encoder, $device);
    }

    public function getProfile(): EncoderProfile
    {
        return $this->profile ?? throw new RuntimeException('Worker hardware capabilities have not been probed');
    }

    public function getBitrateMultiplier(): float
    {
        return $this->bitrateMultiplier;
    }

    /**
     * Probe hardware capabilities and produce the resolved EncoderProfile.
     */
    private function probe(): EncoderProfile
    {
        // If a specific encoder is configured, use it directly
        if ($this->preferredEncoder !== '' && $this->preferredEncoder !== 'auto') {
            $accel = HardwareAccelerator::fromEncoderName($this->preferredEncoder);
            if ($accel !== HardwareAccelerator::None) {
                $profile = EncoderProfile::fromEncoderName($this->preferredEncoder, $this->hwAccelDevice);
                if ($this->validateEncoder($accel, $profile->encoder)) {
                    $this->logger->info('Using configured hardware encoder', [
                        'encoder' => $profile->encoder,
                        'accelerator' => $accel->value,
                    ]);

                    return $profile;
                }
                $this->logger->warning('Configured encoder not available, falling back', [
                    'encoder' => $this->preferredEncoder,
                ]);

                return EncoderProfile::software();
            }
            // Software encoder specified (libx265, libx264, etc.) — use as-is
            return EncoderProfile::software($this->preferredEncoder);
        }

        // Auto-detect: probe available hwaccels
        $availableHwaccels = $this->probeHwaccels();
        $availableEncoders = $this->probeEncoders();

        // Priority order for auto-detection
        $candidates = [
            HardwareAccelerator::Nvenc,
            HardwareAccelerator::Qsv,
            HardwareAccelerator::Vaapi,
            HardwareAccelerator::Amf,
            HardwareAccelerator::VideoToolbox,
        ];

        foreach ($candidates as $candidate) {
            $hwaccelMethod = $candidate->ffmpegHwaccelMethod();
            if (!in_array($hwaccelMethod, $availableHwaccels, true)) {
                continue;
            }

            $encoder = $candidate->hevcEncoder();
            if (!in_array($encoder, $availableEncoders, true)) {
                continue;
            }

            $profile = EncoderProfile::fromEncoderName(
                $encoder,
                $this->hwAccelDevice ?: $candidate->defaultDevicePath(),
            );

            if ($this->validateEncoder($candidate, $encoder)) {
                $this->logger->info('Auto-detected hardware encoder', [
                    'encoder' => $encoder,
                    'accelerator' => $candidate->value,
                    'device' => $profile->hwaccelDevice,
                ]);

                return $profile;
            }
        }

        $this->logger->info('No hardware accelerator available, using software encoding');

        return EncoderProfile::software();
    }

    /**
     * @return list<string>
     */
    private function probeHwaccels(): array
    {
        $output = $this->exec([$this->ffmpegPath, '-hwaccels']);

        $methods = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || str_contains($line, 'Hardware acceleration')) {
                continue;
            }
            $methods[] = $line;
        }

        $this->logger->debug('Detected hwaccel methods', ['methods' => $methods]);

        return $methods;
    }

    /**
     * @return list<string>
     */
    private function probeEncoders(): array
    {
        $output = $this->exec([$this->ffmpegPath, '-encoders']);

        $encoders = [];
        $hwPatterns = ['nvenc', 'vaapi', 'videotoolbox', '_qsv', '_amf'];
        foreach (explode("\n", $output) as $line) {
            foreach ($hwPatterns as $pattern) {
                if (str_contains($line, $pattern)) {
                    if (preg_match('/^\s*\S+\s+(\S+)\s+/', $line, $matches)) {
                        $encoders[] = $matches[1];
                    }
                }
            }
        }

        $this->logger->debug('Detected hardware encoders', ['encoders' => $encoders]);

        return $encoders;
    }

    /**
     * Validate that an encoder is functional by attempting a minimal test encode.
     * For VAAPI/QSV/AMF encoders, frames must be uploaded to GPU memory first.
     */
    private function validateEncoder(HardwareAccelerator $accel, string $encoder, ?string $configuredDevice = null): bool
    {
        $command = [$this->ffmpegPath, '-nostdin', '-y', '-threads', '1', '-filter_threads', '1', '-filter_complex_threads', '1'];
        if ($accel->requiresDevicePath()) {
            $device = $configuredDevice ?: ($this->hwAccelDevice ?: $accel->defaultDevicePath());
            $method = $accel->ffmpegHwaccelMethod();
            array_push($command, '-init_hw_device', sprintf('%s=%s_0:%s', $method, $method, $device));
        }
        // The test frame takes the smallest tier the ladder encodes: hardware encoders have
        // minimum sizes, and AMD's RDNA4 HEVC encoder refuses widths below 384.
        $tier = QualityTier::p360();
        array_push($command, '-f', 'lavfi', '-i', sprintf('nullsrc=s=%dx%d:d=0.1', $tier->width, $tier->height));
        if ($accel->requiresDevicePath()) {
            array_push($command, '-vf', 'format=nv12,hwupload');
        }
        array_push($command, '-c:v', $encoder, '-threads', '1', '-f', 'null', '-');
        $output = $this->exec($command);
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $output)), static fn (string $line): bool => $line !== ''));
        $this->lastDiagnostic = substr(implode(' | ', array_slice($lines, -3)), 0, 512);

        if ($this->lastExitCode !== 0) {
            $this->logger->debug('Encoder validation failed', [
                'encoder' => $encoder,
                'exitCode' => $this->lastExitCode,
            ]);

            return false;
        }

        return true;
    }

    protected int $lastExitCode = 0;

    /** The last lines of the most recent test encode's output, for a startup failure reason. */
    private string $lastDiagnostic = '';

    /** @param list<string> $command */
    protected function exec(array $command): string
    {
        if (ProcessExecutor::usesNativeExecution()) {
            $result = ProcessExecutor::exec($command, 30);
            $this->lastExitCode = $result['code'];
            return $result['output'] . $result['error'];
        }
        $output = tmpfile();
        if ($output === false) {
            throw new RuntimeException('Cannot create hardware probe output stream');
        }
        try {
            $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output];
            $process = proc_open($command, $descriptors, $pipes, env_vars: array_replace(getenv(), ['OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1']));
            if ($process === false) {
                throw new RuntimeException(sprintf('Failed to execute: %s', $command[0]));
            }
            $this->lastExitCode = proc_close($process);
            rewind($output);
            $stdout = stream_get_contents($output);

            return $stdout === false ? '' : $stdout;
        } finally {
            fclose($output);
        }
    }
}
