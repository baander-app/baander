<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Types;

use App\Modules\Essentia\Exceptions\EssentiaException;
use Illuminate\Support\Facades\Process;
use FFI;

/**
 * Represents audio data for Essentia processing
 */
class AudioVector
{
    private array $data;
    private int $sampleRate;
    private int $channels;

    public function __construct(array $data, int $sampleRate = 44100, int $channels = 1)
    {
        if (empty($data)) {
            throw new EssentiaException('Audio data cannot be empty');
        }

        $this->data = $data;
        $this->sampleRate = $sampleRate;
        $this->channels = $channels;
    }

    /**
     * Load audio from any file format supported by FFmpeg
     */
    public static function fromFile(string $filePath, int $sampleRate = 44100, int $channels = 1): self
    {
        if (!file_exists($filePath)) {
            throw new EssentiaException("Audio file not found: {$filePath}");
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'audio_') . '.raw';
        
        try {
            // Use FFmpeg to convert to raw PCM data
            $command = [
                'ffmpeg',
                '-i', $filePath,
                '-f', 'f32le',  // 32-bit float little-endian
                '-acodec', 'pcm_f32le',
                '-ar', (string) $sampleRate,
                '-ac', (string) $channels,
                '-y',  // Overwrite output file
                $tempFile
            ];

            $result = Process::run($command);

            if (!$result->successful()) {
                throw new EssentiaException("FFmpeg conversion failed: " . $result->errorOutput());
            }

            // Read the raw PCM data
            $rawData = file_get_contents($tempFile);
            if ($rawData === false) {
                throw new EssentiaException("Failed to read converted audio data");
            }

            // Convert binary data to float array
            $data = self::unpackFloatArray($rawData);

            // Handle multi-channel audio by converting to mono if needed
            if ($channels > 1) {
                $data = self::convertToMono($data, $channels);
            }

            return new self($data, $sampleRate, 1);

        } finally {
            // Clean up temporary file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Load audio specifically from WAV files (optimized path)
     */
    public static function fromWav(string $filePath, int $sampleRate = 44100): self
    {
        if (!file_exists($filePath)) {
            throw new EssentiaException("WAV file not found: {$filePath}");
        }

        // Check if it's actually a WAV file
        $pathInfo = pathinfo($filePath);
        if (strtolower($pathInfo['extension'] ?? '') !== 'wav') {
            throw new EssentiaException("File is not a WAV file: {$filePath}");
        }

        return self::fromFile($filePath, $sampleRate, 1);
    }

    /**
     * Load audio from a URL or stream
     */
    public static function fromUrl(string $url, int $sampleRate = 44100, int $maxDuration = 300): self
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'audio_stream_') . '.raw';
        
        try {
            $command = [
                'ffmpeg',
                '-i', $url,
                '-f', 'f32le',
                '-acodec', 'pcm_f32le',
                '-ar', (string) $sampleRate,
                '-ac', '1',  // Force mono
                '-t', (string) $maxDuration,  // Limit duration
                '-y',
                $tempFile
            ];

            $result = Process::timeout(60)->run($command);

            if (!$result->successful()) {
                throw new EssentiaException("Failed to load audio from URL: " . $result->errorOutput());
            }

            $rawData = file_get_contents($tempFile);
            if ($rawData === false) {
                throw new EssentiaException("Failed to read streamed audio data");
            }

            $data = self::unpackFloatArray($rawData);
            return new self($data, $sampleRate, 1);

        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Get audio metadata using FFprobe
     */
    public static function getAudioInfo(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new EssentiaException("Audio file not found: {$filePath}");
        }

        $command = [
            'ffprobe',
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $filePath
        ];

        $result = Process::run($command);

        if (!$result->successful()) {
            throw new EssentiaException("Failed to get audio info: " . $result->errorOutput());
        }

        $info = json_decode($result->output(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new EssentiaException("Invalid JSON from ffprobe: " . json_last_error_msg());
        }

        // Extract audio stream information
        $audioStream = null;
        foreach ($info['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'audio') {
                $audioStream = $stream;
                break;
            }
        }

        if (!$audioStream) {
            throw new EssentiaException("No audio stream found in file");
        }

        return [
            'duration' => (float) ($info['format']['duration'] ?? 0),
            'sample_rate' => (int) ($audioStream['sample_rate'] ?? 44100),
            'channels' => (int) ($audioStream['channels'] ?? 1),
            'codec' => $audioStream['codec_name'] ?? 'unknown',
            'bitrate' => (int) ($audioStream['bit_rate'] ?? 0),
            'format' => $info['format']['format_name'] ?? 'unknown',
            'size' => (int) ($info['format']['size'] ?? 0),
        ];
    }

    public static function fromArray(array $data, int $sampleRate = 44100): self
    {
        return new self($data, $sampleRate);
    }

    /**
     * Create AudioVector from raw PCM binary data
     */
    public static function fromRawPCM(string $rawData, int $sampleRate = 44100, string $format = 'f32le'): self
    {
        $data = match($format) {
            'f32le' => self::unpackFloatArray($rawData),
            'i16le' => self::unpackInt16Array($rawData),
            'i24le' => self::unpackInt24Array($rawData),
            'i32le' => self::unpackInt32Array($rawData),
            default => throw new EssentiaException("Unsupported PCM format: {$format}")
        };

        return new self($data, $sampleRate);
    }

    /**
     * Resample audio to a different sample rate using FFmpeg
     */
    public function resample(int $newSampleRate): self
    {
        if ($newSampleRate === $this->sampleRate) {
            return $this;
        }

        $tempInput = tempnam(sys_get_temp_dir(), 'audio_in_') . '.raw';
        $tempOutput = tempnam(sys_get_temp_dir(), 'audio_out_') . '.raw';

        try {
            // Write current data to temp file
            file_put_contents($tempInput, $this->packFloatArray($this->data));

            $command = [
                'ffmpeg',
                '-f', 'f32le',
                '-ar', (string) $this->sampleRate,
                '-ac', '1',
                '-i', $tempInput,
                '-f', 'f32le',
                '-ar', (string) $newSampleRate,
                '-ac', '1',
                '-y',
                $tempOutput
            ];

            $result = Process::run($command);

            if (!$result->successful()) {
                throw new EssentiaException("Resampling failed: " . $result->errorOutput());
            }

            $rawData = file_get_contents($tempOutput);
            if ($rawData === false) {
                throw new EssentiaException("Failed to read resampled audio data");
            }

            $newData = self::unpackFloatArray($rawData);
            return new self($newData, $newSampleRate, 1);

        } finally {
            foreach ([$tempInput, $tempOutput] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getSampleRate(): int
    {
        return $this->sampleRate;
    }

    public function getChannels(): int
    {
        return $this->channels;
    }

    public function getLength(): int
    {
        return count($this->data);
    }

    public function getDuration(): float
    {
        return $this->getLength() / $this->sampleRate;
    }

    public function slice(int $start, int $length): self
    {
        $slicedData = array_slice($this->data, $start, $length);
        return new self($slicedData, $this->sampleRate, $this->channels);
    }

    /**
     * Extract a time-based slice
     */
    public function sliceTime(float $startSeconds, float $durationSeconds): self
    {
        $startSample = (int) ($startSeconds * $this->sampleRate);
        $lengthSamples = (int) ($durationSeconds * $this->sampleRate);
        
        return $this->slice($startSample, $lengthSamples);
    }

    public function normalize(): self
    {
        $max = max(array_map('abs', $this->data));
        if ($max > 0) {
            $normalizedData = array_map(fn($sample) => $sample / $max, $this->data);
            return new self($normalizedData, $this->sampleRate, $this->channels);
        }
        return $this;
    }

    /**
     * Apply gain (amplification/attenuation)
     */
    public function applyGain(float $gainDb): self
    {
        $gainLinear = pow(10, $gainDb / 20);
        $amplifiedData = array_map(fn($sample) => $sample * $gainLinear, $this->data);
        return new self($amplifiedData, $this->sampleRate, $this->channels);
    }

    /**
     * Apply fade in/out
     */
    public function applyFade(float $fadeInSeconds = 0, float $fadeOutSeconds = 0): self
    {
        $data = $this->data;
        $length = count($data);
        $fadeInSamples = (int) ($fadeInSeconds * $this->sampleRate);
        $fadeOutSamples = (int) ($fadeOutSeconds * $this->sampleRate);

        // Apply fade in
        for ($i = 0; $i < min($fadeInSamples, $length); $i++) {
            $factor = $i / $fadeInSamples;
            $data[$i] *= $factor;
        }

        // Apply fade out
        for ($i = 0; $i < min($fadeOutSamples, $length); $i++) {
            $factor = $i / $fadeOutSamples;
            $data[$length - 1 - $i] *= $factor;
        }

        return new self($data, $this->sampleRate, $this->channels);
    }

    public function toCArray(FFI $ffi): FFI\CData
    {
        // Convert PHP array to C array for FFI
        $size = count($this->data);
        $cArray = $ffi->new("float[{$size}]");
        
        for ($i = 0; $i < $size; $i++) {
            $cArray[$i] = (float) $this->data[$i];
        }
        
        return $cArray;
    }

    /**
     * Save audio data to file using FFmpeg
     */
    public function saveToFile(string $filePath, string $format = 'wav'): void
    {
        $tempInput = tempnam(sys_get_temp_dir(), 'audio_save_') . '.raw';

        try {
            // Write raw PCM data
            file_put_contents($tempInput, $this->packFloatArray($this->data));

            $command = [
                'ffmpeg',
                '-f', 'f32le',
                '-ar', (string) $this->sampleRate,
                '-ac', '1',
                '-i', $tempInput,
                '-c:a', match($format) {
                    'wav' => 'pcm_s16le',
                    'flac' => 'flac',
                    'mp3' => 'libmp3lame',
                    'ogg' => 'libvorbis',
                    default => 'pcm_s16le'
                },
                '-y',
                $filePath
            ];

            $result = Process::run($command);

            if (!$result->successful()) {
                throw new EssentiaException("Failed to save audio file: " . $result->errorOutput());
            }

        } finally {
            if (file_exists($tempInput)) {
                unlink($tempInput);
            }
        }
    }

    // Private helper methods

    private static function unpackFloatArray(string $binaryData): array
    {
        if (strlen($binaryData) % 4 !== 0) {
            throw new EssentiaException("Invalid float binary data length");
        }

        return array_values(unpack('f*', $binaryData));
    }

    private static function unpackInt16Array(string $binaryData): array
    {
        if (strlen($binaryData) % 2 !== 0) {
            throw new EssentiaException("Invalid int16 binary data length");
        }

        $intData = array_values(unpack('s*', $binaryData));
        // Convert to float range [-1.0, 1.0]
        return array_map(fn($sample) => $sample / 32768.0, $intData);
    }

    private static function unpackInt24Array(string $binaryData): array
    {
        if (strlen($binaryData) % 3 !== 0) {
            throw new EssentiaException("Invalid int24 binary data length");
        }

        $data = [];
        for ($i = 0; $i < strlen($binaryData); $i += 3) {
            $bytes = substr($binaryData, $i, 3);
            $value = unpack('V', $bytes . "\x00")[1];
            
            // Handle sign extension for 24-bit
            if ($value >= 0x800000) {
                $value -= 0x1000000;
            }
            
            $data[] = $value / 8388608.0; // Convert to float range [-1.0, 1.0]
        }

        return $data;
    }

    private static function unpackInt32Array(string $binaryData): array
    {
        if (strlen($binaryData) % 4 !== 0) {
            throw new EssentiaException("Invalid int32 binary data length");
        }

        $intData = array_values(unpack('l*', $binaryData));
        // Convert to float range [-1.0, 1.0]
        return array_map(fn($sample) => $sample / 2147483648.0, $intData);
    }

    private function packFloatArray(array $data): string
    {
        return pack('f*', ...$data);
    }

    private static function convertToMono(array $data, int $channels): array
    {
        if ($channels === 1) {
            return $data;
        }

        $monoData = [];
        $samplesPerChannel = count($data) / $channels;

        for ($i = 0; $i < $samplesPerChannel; $i++) {
            $sum = 0;
            for ($ch = 0; $ch < $channels; $ch++) {
                $sum += $data[$i * $channels + $ch];
            }
            $monoData[] = $sum / $channels;
        }

        return $monoData;
    }

    public function __toString(): string
    {
        return sprintf(
            'AudioVector(length=%d, sampleRate=%d, channels=%d, duration=%.2fs)',
            $this->getLength(),
            $this->sampleRate,
            $this->channels,
            $this->getDuration()
        );
    }
}