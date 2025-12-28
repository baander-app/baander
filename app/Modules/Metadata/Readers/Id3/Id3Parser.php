<?php

namespace App\Modules\Metadata\Readers\Id3;

use App\Modules\Metadata\Exceptions\InvalidFlacFileException;
use Illuminate\Support\Facades\Log;

/**
 * Low-level ID3v2 tag parser
 * Handles binary reading of ID3v2 metadata
 */
class Id3Parser
{
    private const string LOG_TAG = 'Id3Parser ';
    private const ID3V2_SIGNATURE = 'ID3';
    private const ID3V1_SIGNATURE = 'TAG';

    private string $filePath;
    private $handle;
    private bool $isValid = false;
    private ?string $version = null;
    private array $tags = [];
    private array $pictures = [];

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function parse(): void
    {
        $this->handle = fopen($this->filePath, 'rb');
        if ($this->handle === false) {
            throw InvalidFlacFileException::cannotOpenFile($this->filePath);
        }

        try {
            // Try ID3v2 first
            $this->parseID3v2();

            // Fall back to ID3v1 if no ID3v2 found
            if (!$this->isValid) {
                $this->parseID3v1();
            }

            if (!$this->isValid) {
                throw InvalidFlacFileException::parseError($this->filePath, 'No ID3 tags found');
            }

            Log::debug(self::LOG_TAG . 'Successfully parsed ID3 file', [
                'file' => $this->filePath,
                'version' => $this->version,
                'tags' => count($this->tags),
                'pictures' => count($this->pictures),
            ]);

        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . 'Parse error', [
                'file' => $this->filePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            fclose($this->handle);
        }
    }

    private function parseID3v2(): void
    {
        $signature = fread($this->handle, 3);
        if ($signature !== self::ID3V2_SIGNATURE) {
            return; // Not an ID3v2 file
        }

        $majorVersion = ord(fread($this->handle, 1));
        $minorVersion = ord(fread($this->handle, 1));
        $this->version = "ID3v2.{$majorVersion}.{$minorVersion}";

        // Skip flags (1 byte)
        fread($this->handle, 1);

        // Read tag size (syncsafe integer)
        $sizeBytes = fread($this->handle, 4);
        $tagSize = $this->syncSafeToInt($sizeBytes);

        Log::debug(self::LOG_TAG . 'Found ID3v2 tag', [
            'version' => $this->version,
            'size' => $tagSize,
            'sizeBytes' => bin2hex($sizeBytes),
        ]);

        // Read the entire tag into memory
        fseek($this->handle, 10); // Skip the header
        $tagData = fread($this->handle, $tagSize);

        // Parse frames from the tag data
        $this->parseFrames($tagData, $majorVersion);

        $this->isValid = true;
    }

    private function parseFrames(string $tagData, int $majorVersion): void
    {
        $offset = 0;
        $tagSize = strlen($tagData);

        while ($offset < $tagSize) {
            // Check if we've reached padding (first byte is 0x00)
            if (ord($tagData[$offset]) === 0) {
                break;
            }

            // Frame ID is 4 bytes
            $frameId = substr($tagData, $offset, 4);
            $offset += 4;

            // Frame size is 4 bytes (regular big-endian integer, NOT syncsafe)
            $frameSize = (
                ord($tagData[$offset]) << 24 |
                ord($tagData[$offset + 1]) << 16 |
                ord($tagData[$offset + 2]) << 8 |
                ord($tagData[$offset + 3])
            );
            $offset += 4;

            // Skip flags (2 bytes)
            $offset += 2;

            // Read the frame data
            $frameData = substr($tagData, $offset, $frameSize);
            $offset += $frameSize;

            // Parse frame based on ID
            if ($frameId === 'APIC') {
                $this->parsePictureFrame($frameData);
            } elseif (str_starts_with($frameId, 'T')) {
                // Text frames (frame IDs starting with 'T')
                $this->parseTextFrame($frameId, $frameData);
            }
            // URL frames and other frame types are skipped
        }
    }

    private function parseTextFrame(string $frameId, string $data): void
    {
        $encoding = ord($data[0]);
        $content = substr($data, 1);

        // Decode based on encoding
        $text = $this->decodeText($content, $encoding);

        // Map frame IDs to tag names
        $tagMap = [
            'TIT2' => 'TITLE',
            'TPE1' => 'ARTIST',
            'TALB' => 'ALBUM',
            'TCON' => 'GENRE',
            'TYER' => 'YEAR',
            'TRCK' => 'TRACKNUMBER',
            'TPOS' => 'DISCNUMBER',
            'COMM' => 'COMMENT',
        ];

        $tagName = $tagMap[$frameId] ?? $frameId;

        // Store tag (handle multiple values)
        if (!isset($this->tags[$tagName])) {
            $this->tags[$tagName] = [];
        }
        $this->tags[$tagName][] = $text;
    }

    private function parsePictureFrame(string $data): void
    {
        $offset = 0;

        // Encoding
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

        // Description (null-terminated after encoding)
        $descEnd = strpos($data, "\0", $offset);
        if ($descEnd === false) {
            return;
        }
        $description = $this->decodeText(substr($data, $offset, $descEnd - $offset), $encoding);
        $offset = $descEnd + 1;

        // Image data
        $imageData = substr($data, $offset);

        $this->pictures[] = [
            'type' => $pictureType,
            'mimeType' => $mimeType,
            'description' => $description,
            'imageData' => $imageData,
            'imageSize' => strlen($imageData),
        ];

        Log::debug(self::LOG_TAG . 'Parsed picture', [
            'type' => $pictureType,
            'mimeType' => $mimeType,
            'size' => strlen($imageData),
        ]);
    }

    private function parseID3v1(): void
    {
        fseek($this->handle, -128, SEEK_END);
        $signature = fread($this->handle, 3);

        if ($signature !== self::ID3V1_SIGNATURE) {
            return;
        }

        $this->version = 'ID3v1';

        // Parse fixed-width fields
        $fields = [
            'TITLE' => 30,
            'ARTIST' => 30,
            'ALBUM' => 30,
            'YEAR' => 4,
            'COMMENT' => 30,
            // Skip track number and genre for now
        ];

        $offset = 3; // After signature
        foreach ($fields as $name => $length) {
            $value = trim(fread($this->handle, $length));
            if ($value !== '') {
                $this->tags[$name][] = $value;
            }
            $offset += $length;
        }

        // Track number (optional)
        fseek($this->handle, -125, SEEK_END);
        $track = ord(fread($this->handle, 1));
        if ($track !== 0 && $track <= 255) {
            $this->tags['TRACKNUMBER'][] = (string)$track;
        }

        $this->isValid = true;
    }

    private function decodeText(string $data, int $encoding): string
    {
        return match ($encoding) {
            0 => $data, // ISO-8859-1
            1 => mb_convert_encoding($data, 'UTF-8', 'UTF-16'),
            2 => mb_convert_encoding($data, 'UTF-8', 'UTF-16BE'),
            3 => $data, // UTF-8 already
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

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getPictures(): array
    {
        return $this->pictures;
    }
}
