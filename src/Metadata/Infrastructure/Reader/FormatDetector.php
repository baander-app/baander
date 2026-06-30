<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Reader;

/**
 * Detects the audio format of a file by reading magic bytes from its header.
 *
 * Falls back to extension-based detection when the magic bytes do not match
 * any known format.
 */
final readonly class FormatDetector
{
    private const string FLAC_SIGNATURE = "\x66\x4C\x61\x43";
    private const string ID3V2_SIGNATURE = "\x49\x44\x33";
    private const string OGG_SIGNATURE = "\x4F\x67\x67\x53";

    /**
     * Detect the audio format of a file.
     *
     * Returns 'mp3', 'flac', 'ogg', 'm4a', 'wav', or null if the format
     * cannot be determined.
     */
    public function detect(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $header = fread($handle, 12);
        fclose($handle);

        if ($header === false || strlen($header) < 4) {
            return $this->detectByExtension($path);
        }

        $signature4 = substr($header, 0, 4);

        // ID3v2 tag header: "ID3"
        if (str_starts_with($header, 'ID3')) {
            return 'mp3';
        }

        // FLAC: "fLaC"
        if ($signature4 === self::FLAC_SIGNATURE) {
            return 'flac';
        }

        // OGG: "OggS"
        if ($signature4 === self::OGG_SIGNATURE) {
            return 'ogg';
        }

        // MP3 sync word: 0xFF followed by 0xE0-0xFF (MPEG audio frame)
        if ($header[0] === "\xFF" && (ord($header[1]) & 0xE0) === 0xE0) {
            return 'mp3';
        }

        // M4A / MP4: "ftyp" at offset 4
        if (strlen($header) >= 8 && substr($header, 4, 4) === 'ftyp') {
            return 'm4a';
        }

        // WAV: "RIFF....WAVE"
        if (str_starts_with($header, 'RIFF') && strlen($header) >= 12 && substr($header, 8, 4) === 'WAVE') {
            return 'wav';
        }

        return $this->detectByExtension($path);
    }

    private function detectByExtension(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp3' => 'mp3',
            'flac' => 'flac',
            'ogg', 'oga' => 'ogg',
            'opus' => 'opus',
            'm4a', 'mp4', 'aac' => 'm4a',
            'wav', 'wave' => 'wav',
            default => null,
        };
    }
}
