<?php

namespace App\Modules\Security;

use App\Modules\Security\Exceptions\FileValidationException;
use Illuminate\Support\Facades\Log;
use SplFileInfo;

class MagicByteValidator
{
    private const array AUDIO_MAGIC_BYTES = [
        'mp3' => [
            [['ID3', 0]],
            [["\xFF\xFB", 0]],
            [["\xFF\xFA", 0]],
            [["\xFF\xE2", 0]],
        ],
        'flac' => [['fLaC', 0]],
        'ogg' => [['OggS', 0]],
        'm4a' => [
            [["\x00\x00\x00\x20\x66\x74\x79\x70\x4D\x34\x41", 4]], // M4A
            [["\x00\x00\x00\x18\x66\x74\x79\x70\x6D\x70\x34\x32", 4]], // MP42
        ],
        'wav' => [['RIFF', 0], ['WAVE', 8]],
        'wma' => [[
            "\x30\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x7C",
         0]],
    ];

    private const array VIDEO_MAGIC_BYTES = [
        'mp4' => [
            [["\x00\x00\x00\x18\x66\x74\x79\x70\x69\x73\x6F\x6D", 4]], // ISO MP4
            [["\x00\x00\x00\x20\x66\x74\x79\x70\x69\x73\x6F\x6D", 4]],
        ],
        'mkv' => [[
            "\x1A\x45\xDF\xA3", 0 // EBML
        ]],
        'avi' => [['RIFF', 0], ['AVI ', 8]],
        'webm' => [[
            "\x1A\x45\xDF\xA3", 0 // EBML
        ]],
        'mov' => [
            [["\x00\x00\x00\x14\x66\x74\x79\x70\x71\x74\x20\x20", 4]], // QuickTime
            [["\x6D\x6F\x6F\x76", 0]], // 'moov' atom
        ],
        '3gp' => [
            [["\x00\x00\x00\x20\x66\x74\x79\x70\x69\x73\x6F\x6D", 4]], // 3GPP
        ],
        'wmv' => [[
            "\x30\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x7C",
         0]],
    ];

    private const IMAGE_MAGIC_BYTES = [
        'jpeg' => [[
            "\xFF\xD8\xFF", 0 // JPEG SOI
        ]],
        'jpg' => [[
            "\xFF\xD8\xFF", 0 // JPEG SOI
        ]],
        'png' => [[
            "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A", // PNG
        ]],
        'gif' => [
            ['GIF87a', 0], // GIF87a
            ['GIF89a', 0], // GIF89a
        ],
        'heic' => [[
            "\x00\x00\x00\x20\x66\x74\x79\x70\x68\x65\x69\x63", 4 // HEIC FTYP
        ]],
    ];

    private const array SUBTITLE_MAGIC_BYTES = [
        'srt' => null, // Text format
        'ass' => null, // Advanced SubStation Alpha
        'vtt' => null, // WebVTT
    ];

    private const array FORMAT_CATEGORIES = [
        'mp3' => 'audio',
        'flac' => 'audio',
        'ogg' => 'audio',
        'm4a' => 'audio',
        'wav' => 'audio',
        'wma' => 'audio',
        'mp4' => 'video',
        'mkv' => 'video',
        'avi' => 'video',
        'webm' => 'video',
        'mov' => 'video',
        '3gp' => 'video',
        'wmv' => 'video',
        'jpeg' => 'image',
        'jpg' => 'image',
        'png' => 'image',
        'gif' => 'image',
        'heic' => 'image',
        'srt' => 'subtitle',
        'ass' => 'subtitle',
        'vtt' => 'subtitle',
    ];

    public function isValidAudioFile(string $path): bool
    {
        return $this->validateAgainstFormats($path, self::AUDIO_MAGIC_BYTES);
    }

    public function isValidVideoFile(string $path): bool
    {
        return $this->validateAgainstFormats($path, self::VIDEO_MAGIC_BYTES);
    }

    public function isValidImageFile(string $path): bool
    {
        return $this->validateAgainstFormats($path, self::IMAGE_MAGIC_BYTES);
    }

    public function isValidSubtitleFile(string $path): bool
    {
        // Subtitles are text-based, validated by extension
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return isset(self::SUBTITLE_MAGIC_BYTES[$extension]);
    }

    public function detectFormat(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Check extension-based detection first (faster)
        if (isset(self::FORMAT_CATEGORIES[$extension])) {
            return $extension;
        }

        // Try to detect by magic bytes for unknown extensions
        $allFormats = array_merge(
            array_keys(self::AUDIO_MAGIC_BYTES),
            array_keys(self::VIDEO_MAGIC_BYTES),
            array_keys(self::IMAGE_MAGIC_BYTES)
        );

        return array_find($allFormats, fn($format) => $this->checkMagicBytes($path, $this->getMagicBytesForFormat($format)));
    }

    public function validateAgainstMime(string $path, string $declaredMime): bool
    {
        $format = $this->detectFormat($path);

        if (!$format) {
            return false;
        }

        $category = $this->getFormatCategory($format);

        return match ($category) {
            'audio' => str_starts_with($declaredMime, 'audio/'),
            'video' => str_starts_with($declaredMime, 'video/'),
            'image' => str_starts_with($declaredMime, 'image/'),
            'subtitle' => str_starts_with($declaredMime, 'text/'),
            default => false,
        };
    }

    public function getFormatCategory(string $format): ?string
    {
        return self::FORMAT_CATEGORIES[$format] ?? null;
    }

    private function validateAgainstFormats(string $path, array $formatSignatures): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Check if extension is supported
        if (!isset($formatSignatures[$extension])) {
            return false;
        }

        $signatures = $formatSignatures[$extension];

        // Text-based formats (subtitles) don't have magic bytes
        if ($signatures === null) {
            return true;
        }

        return $this->checkMagicBytes($path, $signatures);
    }

    private function checkMagicBytes(string $path, array $signatures): bool
    {
        if (!file_exists($path) || !is_readable($path)) {
            Log::warning('MagicByteValidator: File not readable', ['path' => $path]);
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            Log::warning('MagicByteValidator: Cannot open file', ['path' => $path]);
            return false;
        }

        try {
            foreach ($signatures as $signature) {
                [$bytes, $offset] = $signature;

                if ($offset > 0) {
                    fseek($handle, $offset);
                }

                $fileBytes = fread($handle, strlen($bytes));

                if ($fileBytes === false) {
                    continue;
                }

                if ($fileBytes === $bytes) {
                    fclose($handle);
                    return true;
                }
            }

            fclose($handle);
            return false;
        } catch (\Exception $e) {
            fclose($handle);
            Log::error('MagicByteValidator: Error reading file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function getMagicBytesForFormat(string $format): array
    {
        return self::AUDIO_MAGIC_BYTES[$format]
            ?? self::VIDEO_MAGIC_BYTES[$format]
            ?? self::IMAGE_MAGIC_BYTES[$format]
            ?? self::SUBTITLE_MAGIC_BYTES[$format]
            ?? [];
    }
}
