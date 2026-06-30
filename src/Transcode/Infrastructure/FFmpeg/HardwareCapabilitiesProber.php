<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;

/**
 * Probes available hardware accelerators at server boot.
 *
 * Runs `ffmpeg -hwaccels` and `ffmpeg -encoders` to detect GPU capabilities,
 * then validates each detected accelerator with a test encode.
 * Produces an EncoderProfile for the best available hardware, or falls back to software.
 */
class HardwareCapabilitiesProber implements Bootable
{
    private bool $booted = false;
    private ?EncoderProfile $profile = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $ffmpegPath = '/usr/local/bin/ffmpeg',
        private readonly string $preferredEncoder = '',
        private readonly string $hwAccelDevice = '',
        private readonly float $bitrateMultiplier = 1.0,
    ) {
    }

    public function boot(array $runtimeConfiguration = []): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $this->profile = $this->probe();
    }

    public function getProfile(): EncoderProfile
    {
        return $this->profile ?? throw new RuntimeException('HardwareCapabilitiesProber not booted');
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
        $output = $this->exec(sprintf('%s -hwaccels', $this->ffmpegPath));

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
        $output = $this->exec(sprintf('%s -encoders', $this->ffmpegPath));

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
    private function validateEncoder(HardwareAccelerator $accel, string $encoder): bool
    {
        // Build init_hw_device flag for accelerators requiring device path
        $initDevice = '';
        if ($accel->requiresDevicePath()) {
            $device = $this->hwAccelDevice ?: $accel->defaultDevicePath();
            $method = $accel->ffmpegHwaccelMethod();
            $initDevice = sprintf('-init_hw_device %s=%s_0:%s', $method, $method, escapeshellarg($device));
        }

        // Build upload filter for hardware encoders that need frames in GPU memory
        $uploadFilter = match (true) {
            $accel === HardwareAccelerator::Nvenc => '',
            $accel === HardwareAccelerator::VideoToolbox => '',
            $accel->requiresDevicePath() => ' -vf ' . escapeshellarg('format=nv12,hwupload'),
            default => '',
        };

        $cmd = sprintf(
            '%s -y %s -f lavfi -i nullsrc=s=256x256:d=0.1%s -c:v %s -f null - 2>&1',
            $this->ffmpegPath,
            $initDevice !== '' ? $initDevice . ' ' : '',
            $uploadFilter,
            $encoder,
        );

        $this->exec($cmd);

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

    protected function exec(string $cmd): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if ($process === false) {
            throw new RuntimeException(sprintf('Failed to execute: %s', $cmd));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->lastExitCode = proc_close($process);

        return $stdout . "\n" . $stderr;
    }
}
