<?php

declare(strict_types=1);

namespace Baander\MediaMetadata;

/**
 * Detects the audio format of a file by reading magic bytes from its header.
 *
 * Falls back to extension-based detection when the magic bytes do not match
 * any known format.
 */
final readonly class FormatDetector
{
    private const string FLAC_SIGNATURE = "\x66\x4C\x61\x43";
    private const string OGG_SIGNATURE = "\x4F\x67\x67\x53";

    /**
     * Detect the audio format of a file.
     *
     * Returns a canonical container name, or null if the format
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

        try {
            $header = fread($handle, 16);
            if ($header !== false && str_starts_with($header, 'ID3')) {
                return $this->detectAfterId3($handle, $header, $path);
            }
        } finally {
            fclose($handle);
        }

        if ($header === false || strlen($header) < 4) {
            return $this->detectByExtension($path);
        }

        $signature4 = substr($header, 0, 4);

        if ($header === "\x30\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x6C") {
            return 'wma';
        }

        if ($this->isAdts($header)) {
            return 'aac';
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

        if ($signature4 === 'FORM' && in_array(substr($header, 8, 4), ['AIFF', 'AIFC'], true)) {
            return 'aiff';
        }

        return $this->detectByExtension($path);
    }

    /** @param resource $handle */
    private function detectAfterId3($handle, string $header, string $path): string
    {
        if (strlen($header) >= 10 && (ord($header[6]) | ord($header[7]) | ord($header[8]) | ord($header[9])) < 128) {
            $tagSize = (ord($header[6]) << 21) | (ord($header[7]) << 14) | (ord($header[8]) << 7) | ord($header[9]);
            $footerSize = ord($header[3]) === 4 && (ord($header[5]) & 0x10) !== 0 ? 10 : 0;
            if (fseek($handle, 10 + $tagSize + $footerSize) === 0) {
                $audioHeader = fread($handle, 7);
                if ($audioHeader !== false && $this->isAdts($audioHeader)) {
                    return 'aac';
                }
                if ($audioHeader !== false && strlen($audioHeader) >= 2 && $audioHeader[0] === "\xFF" && (ord($audioHeader[1]) & 0xE6) === 0xE2) {
                    return 'mp3';
                }
            }
        }

        return $this->detectByExtension($path) === 'aac' ? 'aac' : 'mp3';
    }

    private function isAdts(string $header): bool
    {
        return strlen($header) >= 7
            && $header[0] === "\xFF"
            && (ord($header[1]) & 0xF6) === 0xF0
            && ((ord($header[2]) >> 2) & 0x0F) < 13;
    }

    private function detectByExtension(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp3' => 'mp3',
            'flac' => 'flac',
            'ogg', 'oga' => 'ogg',
            'opus' => 'opus',
            'm4a', 'mp4', 'mp4a', 'm4b' => 'm4a',
            'wav', 'wave' => 'wav',
            'wma' => 'wma',
            'aif', 'aiff', 'aifc' => 'aiff',
            'aac' => 'aac',
            default => null,
        };
    }
}
