<?php

namespace App\Modules\Metadata\Readers;

use App\Modules\Metadata\Contracts\FormatDetectorInterface;
use App\Modules\Metadata\Exceptions\InvalidFlacFileException;
use Illuminate\Support\Facades\Log;

/**
 * Format detector using magic bytes
 * Provides reliable file format detection without reading entire files
 */
class FormatDetector implements FormatDetectorInterface
{
    private const string LOG_TAG = 'FormatDetector ';

    // FLAC magic bytes: "fLaC" (0x664C6143)
    private const FLAC_SIGNATURE = "\x66\x4C\x61\x43";

    // ID3v2 magic bytes: "ID3" (0x494433)
    private const ID3V2_SIGNATURE = "\x49\x44\x33";

    // OGG magic bytes: "OggS" (0x4F676753)
    private const OGG_SIGNATURE = "\x4F\x67\x67\x53";

    // MP4/FTYP magic bytes (simplified detection)
    private const MP4_SIGNATURES = [
        "\x00\x00\x00\x18\x66\x74\x79\x70", // ftyp box
        "\x00\x00\x00\x20\x66\x74\x79\x70",
        "\x00\x00\x00\x14\x66\x74\x79\x70",
    ];

    public function detect(string $filePath): string
    {
        if (!file_exists($filePath)) {
            Log::warning(self::LOG_TAG . 'File does not exist', ['file' => $filePath]);
            return 'unknown';
        }

        try {
            $signature = $this->getSignature($filePath, 4);

            // Check FLAC
            if ($signature === self::FLAC_SIGNATURE) {
                Log::debug(self::LOG_TAG . 'Detected FLAC format', ['file' => $filePath]);
                return 'flac';
            }

            // Check ID3v2 (MP3) - signature is "ID3" followed by version byte
            if (str_starts_with($signature, self::ID3V2_SIGNATURE)) {
                Log::debug(self::LOG_TAG . 'Detected ID3/MP3 format', ['file' => $filePath]);
                return 'id3';
            }

            // Check OGG
            if ($signature === self::OGG_SIGNATURE) {
                Log::debug(self::LOG_TAG . 'Detected OGG format', ['file' => $filePath]);
                return 'ogg';
            }

            // Check MP4 (need 8 bytes)
            $signature8 = $this->getSignature($filePath, 8);
            foreach (self::MP4_SIGNATURES as $mp4Sig) {
                if ($signature8 === $mp4Sig) {
                    Log::debug(self::LOG_TAG . 'Detected MP4 format', ['file' => $filePath]);
                    return 'mp4';
                }
            }

            Log::debug(self::LOG_TAG . 'Unknown format', [
                'file' => $filePath,
                'signature' => bin2hex($signature)
            ]);

            return 'unknown';
        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . 'Error detecting format', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return 'unknown';
        }
    }

    public function isFlac(string $filePath): bool
    {
        return $this->detect($filePath) === 'flac';
    }

    public function isId3(string $filePath): bool
    {
        return $this->detect($filePath) === 'id3';
    }

    public function getSignature(string $filePath, int $length = 4): string
    {
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new InvalidFlacFileException("Cannot open file: {$filePath}");
        }

        $signature = fread($handle, $length);
        fclose($handle);

        if ($signature === false) {
            throw new InvalidFlacFileException("Cannot read file signature: {$filePath}");
        }

        return $signature;
    }
}
