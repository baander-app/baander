<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Reader;

use Psr\Log\LoggerInterface;

/**
 * Low-level ID3v2/v1 tag parser.
 *
 * Reads ID3 metadata directly from the binary file:
 * - ID3v2: frame-based structure with text frames (T*) and APIC picture frames
 * - ID3v1: fixed-width 128-byte footer with basic fields
 */
final class Id3Parser
{
    private const string ID3V2_SIGNATURE = 'ID3';
    private const string ID3V1_SIGNATURE = 'TAG';

    private bool $isValid = false;
    private ?string $version = null;
    /** @var array<string, list<string>> */
    private array $tags = [];
    /** @var list<array{type: int, mimeType: string, description: string, imageData: string, imageSize: int}> */
    private array $pictures = [];

    public function __construct(
        private readonly string $filePath,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function parse(): void
    {
        $handle = @fopen($this->filePath, 'rb');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open ID3 file: {$this->filePath}");
        }

        try {
            $this->parseId3v2($handle);

            if (!$this->isValid) {
                $this->parseId3v1($handle);
            }

            if (!$this->isValid) {
                throw new \RuntimeException("No ID3 tags found in: {$this->filePath}");
            }

            $this->logger->debug('Id3Parser: parsed ID3 file', [
                'path' => $this->filePath,
                'version' => $this->version,
                'tags' => count($this->tags),
                'pictures' => count($this->pictures),
            ]);
        } finally {
            fclose($handle);
        }
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    /** @return array<string, list<string>> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @return list<array{type: int, mimeType: string, description: string, imageData: string, imageSize: int}> */
    public function getPictures(): array
    {
        return $this->pictures;
    }

    // ---- Private methods ----

    /**
     * @param resource $handle
     */
    private function parseId3v2($handle): void
    {
        $signature = fread($handle, 3);

        if ($signature !== self::ID3V2_SIGNATURE) {
            return;
        }

        $majorVersion = ord(fread($handle, 1));
        $minorVersion = ord(fread($handle, 1));
        $this->version = "ID3v2.{$majorVersion}.{$minorVersion}";

        // Skip flags (1 byte)
        fread($handle, 1);

        // Read tag size (syncsafe integer)
        $sizeBytes = fread($handle, 4);
        $tagSize = $this->syncSafeToInt($sizeBytes);

        $this->logger->debug('Id3Parser: found ID3v2 tag', [
            'version' => $this->version,
            'size' => $tagSize,
        ]);

        fseek($handle, 10); // Skip header
        $tagData = fread($handle, $tagSize);

        $this->parseFrames($tagData, $majorVersion);

        $this->isValid = true;
    }

    private function parseFrames(string $tagData, int $majorVersion): void
    {
        $offset = 0;
        $tagSize = strlen($tagData);

        while ($offset < $tagSize) {
            if (ord($tagData[$offset]) === 0) {
                break; // Padding
            }

            $frameId = substr($tagData, $offset, 4);
            $offset += 4;

            // Frame size: 4 bytes big-endian
            $frameSize = (
                ord($tagData[$offset]) << 24 |
                ord($tagData[$offset + 1]) << 16 |
                ord($tagData[$offset + 2]) << 8 |
                ord($tagData[$offset + 3])
            );
            $offset += 4;

            // Skip flags (2 bytes)
            $offset += 2;

            $frameData = substr($tagData, $offset, $frameSize);
            $offset += $frameSize;

            if ($frameId === 'APIC') {
                $this->parsePictureFrame($frameData);
            } elseif ($frameId === 'COMM') {
                $this->parseCommentFrame($frameData);
            } elseif (str_starts_with($frameId, 'T')) {
                $this->parseTextFrame($frameId, $frameData);
            }
        }
    }

    private function parseTextFrame(string $frameId, string $data): void
    {
        $encoding = ord($data[0]);
        $content = substr($data, 1);
        $text = $this->decodeText($content, $encoding);

        $tagMap = [
            'TIT2' => 'TITLE',
            'TPE1' => 'ARTIST',
            'TALB' => 'ALBUM',
            'TPE2' => 'ALBUMARTIST',
            'TCON' => 'GENRE',
            'TYER' => 'YEAR',
            'TDRC' => 'YEAR',
            'TRCK' => 'TRACKNUMBER',
            'TPOS' => 'DISCNUMBER',
            'TBPM' => 'BPM',
            'TCOM' => 'COMPOSER',
            'TLEN' => 'LENGTH',
        ];

        $tagName = $tagMap[$frameId] ?? $frameId;

        $this->tags[$tagName][] = $text;
    }

    /**
     * Parse a COMM (comment) frame.
     *
     * Structure: encoding (1) + language (3) + short description (null-term) + full text.
     */
    private function parseCommentFrame(string $data): void
    {
        if (strlen($data) < 5) {
            return;
        }

        $encoding = ord($data[0]);
        // Skip language (3 bytes), start of content at offset 4
        $content = substr($data, 4);

        // Skip short content description (null-terminated)
        $nullPos = strpos($content, "\0");
        if ($nullPos !== false) {
            $content = substr($content, $nullPos + 1);
        }

        $text = $this->decodeText($content, $encoding);

        if ($text !== '') {
            $this->tags['COMMENT'][] = $text;
        }
    }

    private function parsePictureFrame(string $data): void
    {
        $offset = 0;
        $encoding = ord($data[$offset]);
        $offset += 1;

        // MIME type (null-terminated)
        $mimeEnd = strpos($data, "\0", $offset);
        if ($mimeEnd === false) {
            return;
        }

        $mimeType = substr($data, $offset, $mimeEnd - $offset);
        $offset = $mimeEnd + 1;

        // Picture type (1 byte)
        $pictureType = ord($data[$offset]);
        $offset += 1;

        // Description (null-terminated)
        $descEnd = strpos($data, "\0", $offset);
        if ($descEnd === false) {
            return;
        }

        $description = $this->decodeText(substr($data, $offset, $descEnd - $offset), $encoding);
        $offset = $descEnd + 1;

        $imageData = substr($data, $offset);

        $this->pictures[] = [
            'type' => $pictureType,
            'mimeType' => $mimeType,
            'description' => $description,
            'imageData' => $imageData,
            'imageSize' => strlen($imageData),
        ];
    }

    /**
     * @param resource $handle
     */
    private function parseId3v1($handle): void
    {
        fseek($handle, -128, SEEK_END);
        $signature = fread($handle, 3);

        if ($signature !== self::ID3V1_SIGNATURE) {
            return;
        }

        $this->version = 'ID3v1';

        $fields = [
            'TITLE' => 30,
            'ARTIST' => 30,
            'ALBUM' => 30,
            'YEAR' => 4,
            'COMMENT' => 30,
        ];

        foreach ($fields as $name => $length) {
            $value = trim(fread($handle, $length));
            if ($value !== '') {
                $this->tags[$name][] = $value;
            }
        }

        // Track number (optional, in v1.1)
        // ID3v1.1 layout (last 3 bytes): track (1), zero (1), genre (1)
        fseek($handle, -3, SEEK_END);
        $track = ord(fread($handle, 1));

        if ($track !== 0 && $track <= 255) {
            $this->tags['TRACKNUMBER'][] = (string) $track;
        }

        $this->isValid = true;
    }

    private function decodeText(string $data, int $encoding): string
    {
        return match ($encoding) {
            0 => $data, // ISO-8859-1
            1 => mb_convert_encoding($data, 'UTF-8', 'UTF-16'),
            2 => mb_convert_encoding($data, 'UTF-8', 'UTF-16BE'),
            3 => $data, // UTF-8
            default => $data,
        };
    }

    private function syncSafeToInt(string $bytes): int
    {
        $result = 0;

        foreach (str_split($bytes) as $i => $byte) {
            $result |= ord($byte) << (7 * (3 - $i));
        }

        return $result;
    }
}
