<?php

declare(strict_types=1);

namespace App\Filesystem\Mime;

/**
 * Detects MIME types by reading file magic bytes.
 *
 * Unlike finfo_file(), this implementation provides fine-grained control
 * over detection and handles edge cases for media files.
 */
final class MimeDetector implements \App\Filesystem\Application\Port\MimeDetectorPortInterface
{
    /** Magic byte signatures keyed by MIME type. */
    private const array SIGNATURES = [
        // Images
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png' => ["\x89PNG\r\n\x1A\n"],
        'image/gif' => ["GIF87a", "GIF89a"],
        'image/webp' => ["RIFF", true], // WebP: RIFF....WEBP
        'image/tiff' => ["\x49\x49\x2A\x00", "\x4D\x4D\x00\x2A"],
        'image/bmp' => ["BM"],
        'image/svg+xml' => ["<?xml", "<svg"],

        // Audio
        'audio/mpeg' => ["\xFF\xFB", "\xFF\xF3", "\xFF\xF2", "ID3"],
        'audio/flac' => ["fLaC"],
        'audio/ogg' => ["OggS"],
        'audio/wav' => ["RIFF", true], // WAV: RIFF....WAVE
        'audio/aac' => ["\xFF\xF1", "\xFF\xF9"],
        'audio/mp4' => [], // Detected by offset 4: "ftyp"
        'audio/x-m4a' => [], // Same container as mp4
        'audio/wma' => [], // ASF header
        'audio/opus' => ["OggS"], // Detected alongside ogg

        // Video
        'video/mp4' => [], // ftyp at offset 4
        'video/x-matroska' => ["\x1A\x45\xDF\xA3"], // EBML header
        'video/avi' => ["RIFF", true], // AVI: RIFF....AVI
        'video/webm' => ["\x1A\x45\xDF\xA3"], // WebM is Matroska
        'video/x-ms-wmv' => [], // ASF header
        'video/x-msvideo' => ["RIFF", true], // AVI

        // Documents
        'application/pdf' => ["%PDF"],
    ];

    /** Container format sub-type detection offsets. */
    private const array CONTAINER_TYPES = [
        'RIFF' => [
            ['offset' => 8, 'length' => 4, 'value' => 'WAVE', 'mime' => 'audio/wav'],
            ['offset' => 8, 'length' => 4, 'value' => 'AVI ', 'mime' => 'video/avi'],
            ['offset' => 8, 'length' => 4, 'value' => 'WEBP', 'mime' => 'image/webp'],
        ],
    ];

    /**
     * Detect the MIME type of a file by reading its magic bytes.
     */
    public function detect(string $path): string
    {
        if (!file_exists($path) || !is_readable($path)) {
            return 'application/octet-stream';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return 'application/octet-stream';
        }

        $header = fread($handle, 32);
        fclose($handle);

        if ($header === false || strlen($header) < 4) {
            return 'application/octet-stream';
        }

        return $this->detectFromBytes($header, $path);
    }

    /**
     * Detect MIME type from raw header bytes.
     */
    public function detectFromBytes(string $header, string $path = ''): string
    {
        // Check for MP4/M4A container (ftyp at offset 4)
        if (strlen($header) >= 12 && substr($header, 4, 4) === 'ftyp') {
            $brand = substr($header, 8, 4);

            return match ($brand) {
                'M4A ', 'm4a ', 'isom' => 'audio/x-m4a',
                'M4V ', 'm4v ' => 'video/mp4',
                'mp42', 'mp41', 'isom', 'iso2', 'avc1' => $this->guessMp4Type($path),
                default => 'video/mp4',
            };
        }

        // Check container sub-types (RIFF)
        if (str_starts_with($header, 'RIFF') && strlen($header) >= 12) {
            $subtype = substr($header, 8, 4);
            return match ($subtype) {
                'WAVE' => 'audio/wav',
                'AVI ' => 'video/avi',
                'WEBP' => 'image/webp',
                default => 'application/octet-stream',
            };
        }

        // Check standard magic byte signatures
        foreach (self::SIGNATURES as $mime => $signatures) {
            foreach ($signatures as $signature) {
                if ($signature === true) {
                    // Container type — already handled above for RIFF
                    continue;
                }

                if (str_starts_with($header, $signature)) {
                    // Special case: OGG could be audio/ogg, video/ogg, or audio/opus
                    if ($mime === 'audio/ogg') {
                        return $this->detectOggType($path);
                    }

                    return $mime;
                }
            }
        }

        // Check SVG by content (not magic bytes)
        if (str_contains($header, '<svg') || str_contains($header, '<?xml')) {
            if ($path !== '') {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($ext === 'svg') {
                    return 'image/svg+xml';
                }
            }
        }

        // Fallback to finfo
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($path ?: 'php://memory');

        return $detected !== false ? $detected : 'application/octet-stream';
    }

    /**
     * Get the file extension for a given MIME type.
     */
    public function getExtension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/tiff' => 'tiff',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
            'audio/mpeg' => 'mp3',
            'audio/flac' => 'flac',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'audio/aac' => 'aac',
            'audio/x-m4a' => 'm4a',
            'audio/opus' => 'opus',
            'video/mp4' => 'mp4',
            'video/x-matroska', 'video/webm' => $mimeType === 'video/webm' ? 'webm' : 'mkv',
            'video/avi' => 'avi',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    /**
     * Check if a MIME type is an audio file.
     */
    public function isAudio(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'audio/');
    }

    /**
     * Check if a MIME type is a video file.
     */
    public function isVideo(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'video/');
    }

    /**
     * Check if a MIME type is an image file.
     */
    public function isImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    /**
     * Check if a MIME type is a media file (audio, video, or image).
     */
    public function isMedia(string $mimeType): bool
    {
        return $this->isAudio($mimeType) || $this->isVideo($mimeType) || $this->isImage($mimeType);
    }

    private function detectOggType(string $path): string
    {
        if ($path === '' || !is_readable($path)) {
            return 'audio/ogg';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return 'audio/ogg';
        }

        // Skip the OggS header (28 bytes) and read the codec identifier
        fseek($handle, 28);
        $codecHeader = fread($handle, 8);
        fclose($handle);

        if ($codecHeader === false) {
            return 'audio/ogg';
        }

        // Opus: "OpusHead" at offset 28
        if (str_starts_with($codecHeader, 'Opus')) {
            return 'audio/opus';
        }

        // Theora: "\x80theora"
        if ($codecHeader[0] === "\x80" && str_contains($codecHeader, 'theora')) {
            return 'video/ogg';
        }

        // Vorbis: "\x01vorbis"
        if ($codecHeader[0] === "\x01" && str_contains($codecHeader, 'vorbis')) {
            return 'audio/ogg';
        }

        return 'audio/ogg';
    }

    private function guessMp4Type(string $path): string
    {
        if ($path !== '') {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            return match ($ext) {
                'm4a' => 'audio/x-m4a',
                'm4v' => 'video/mp4',
                default => 'video/mp4',
            };
        }

        return 'video/mp4';
    }
}
