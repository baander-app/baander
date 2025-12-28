<?php

namespace App\Modules\Metadata\Readers\Flac;

use App\Modules\Metadata\Exceptions\InvalidFlacFileException;
use Illuminate\Support\Facades\Log;

/**
 * Low-level FLAC file parser
 * Handles binary reading of FLAC metadata blocks
 *
 * FLAC format structure:
 * - 4 bytes: "fLaC" signature
 * - Metadata blocks (each has header + data):
 *   - 1 byte: Last block flag (1 bit) + Block type (7 bits)
 *   - 3 bytes: Block length (24-bit big endian)
 *   - N bytes: Block data
 */
class FlacParser
{
    private const string LOG_TAG = 'FlacParser ';
    private const FLAC_SIGNATURE = "fLaC";

    // Metadata block types
    private const BLOCK_STREAMINFO = 0;
    private const BLOCK_PADDING = 1;
    private const BLOCK_APPLICATION = 2;
    private const BLOCK_SEEKTABLE = 3;
    private const BLOCK_VORBIS_COMMENT = 4;
    private const BLOCK_CUESHEET = 5;
    private const BLOCK_PICTURE = 6;

    private string $filePath;
    private $handle;
    private bool $isValid = false;
    private array $metadataBlocks = [];
    private ?array $streamInfo = null;
    private ?array $vorbisCommentBlock = null;
    private array $pictureBlocks = [];
    private ?array $seektableBlock = null;

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
            $this->validateSignature();
            $this->parseMetadataBlocks();
            $this->isValid = true;

            Log::debug(self::LOG_TAG . 'Successfully parsed FLAC file', [
                'file' => $this->filePath,
                'blocks' => count($this->metadataBlocks),
                'hasVorbisComments' => $this->hasVorbisComments(),
                'hasPictures' => count($this->pictureBlocks) > 0,
                'hasSeektable' => $this->hasSeektable(),
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

    private function validateSignature(): void
    {
        $signature = fread($this->handle, 4);
        if ($signature !== self::FLAC_SIGNATURE) {
            throw InvalidFlacFileException::invalidSignature($this->filePath, $signature);
        }
    }

    private function parseMetadataBlocks(): void
    {
        $isLast = false;
        $blockNumber = 0;

        while (!$isLast) {
            $header = $this->readMetadataBlockHeader();
            $isLast = $header['isLast'];
            $blockType = $header['type'];
            $blockLength = $header['length'];

            Log::debug(self::LOG_TAG . 'Reading metadata block', [
                'block' => $blockNumber,
                'type' => $this->getBlockTypeName($blockType),
                'length' => $blockLength,
                'isLast' => $isLast,
            ]);

            $blockData = fread($this->handle, $blockLength);
            if ($blockData === false || strlen($blockData) !== $blockLength) {
                throw InvalidFlacFileException::parseError(
                    $this->filePath,
                    "Failed to read block data (expected {$blockLength} bytes)"
                );
            }

            // Store block info with raw data
            $this->metadataBlocks[] = [
                'type' => $this->getBlockTypeName($blockType),
                'typeCode' => $blockType,
                'isLast' => $isLast,
                'length' => $blockLength,
                'data' => $blockData, // Store raw block data for writing
            ];

            // Process specific block types
            switch ($blockType) {
                case self::BLOCK_STREAMINFO:
                    $this->parseStreamInfo($blockData);
                    break;
                case self::BLOCK_VORBIS_COMMENT:
                    $this->parseVorbisCommentBlock($blockData);
                    break;
                case self::BLOCK_PICTURE:
                    $this->pictureBlocks[] = self::parsePictureBlock($blockData);
                    break;
                case self::BLOCK_SEEKTABLE:
                    $this->parseSeektableBlock($blockData);
                    break;
                default:
                    // Ignore other block types
                    break;
            }

            $blockNumber++;
        }
    }

    private function readMetadataBlockHeader(): array
    {
        $headerByte = ord(fread($this->handle, 1));
        $isLast = ($headerByte & 0x80) !== 0;
        $blockType = $headerByte & 0x7F;

        // Read 24-bit big-endian length
        $lengthBytes = fread($this->handle, 3);
        if (strlen($lengthBytes) !== 3) {
            throw InvalidFlacFileException::parseError(
                $this->filePath,
                "Failed to read block header length"
            );
        }

        $length = unpack('N', "\x00" . $lengthBytes)[1];

        return [
            'isLast' => $isLast,
            'type' => $blockType,
            'length' => $length,
        ];
    }

    private function parseStreamInfo(string $data): void
    {
        // STREAMINFO is always 34 bytes
        if (strlen($data) < 34) {
            Log::warning(self::LOG_TAG . 'STREAMINFO block too short', [
                'file' => $this->filePath,
                'length' => strlen($data),
            ]);
            return;
        }

        // Parse the bit-packed STREAMINFO structure
        // Bytes 0-1: Minimum block size (16 bits)
        // Bytes 2-3: Maximum block size (16 bits)
        // Bytes 4-6: Minimum frame size (24 bits)
        // Bytes 7-9: Maximum frame size (24 bits)
        // Bytes 10-13: Sample rate, channels, bits per sample (packed in 32 bits)
        // Bytes 14-17: Total samples (36 bits, but we only read 32 here)
        // Bytes 18-33: MD5 signature (16 bytes)

        $unpacked = unpack('Nupper/Nlower', substr($data, 10, 8));

        $upper = $unpacked['upper'];
        $lower = $unpacked['lower'];

        // Extract fields from bit-packed structure
        $sampleRate = ($upper >> 12) & 0xFFFFF;
        $channels = (($upper >> 9) & 0x7) + 1;
        $bitsPerSample = (($upper >> 4) & 0x1F) + 1;

        // Total samples is split across the boundary
        $totalSamples = (($upper & 0xF) << 32) | $lower;

        $this->streamInfo = [
            'sampleRate' => $sampleRate,
            'channels' => $channels,
            'bitsPerSample' => $bitsPerSample,
            'totalSamples' => $totalSamples,
        ];

        Log::debug(self::LOG_TAG . 'Parsed STREAMINFO', $this->streamInfo);
    }

    private function parseVorbisCommentBlock(string $data): void
    {
        $offset = 0;

        // Vendor string length (4 bytes, little endian)
        if (strlen($data) < 4) {
            return;
        }

        $vendorLength = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        if (strlen($data) < $offset + $vendorLength) {
            return;
        }

        $vendor = substr($data, $offset, $vendorLength);
        $offset += $vendorLength;

        // Comment count (4 bytes, little endian)
        if (strlen($data) < $offset + 4) {
            return;
        }

        $commentCount = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        Log::debug(self::LOG_TAG . 'Parsing Vorbis comments', [
            'vendor' => $vendor,
            'commentCount' => $commentCount,
        ]);

        // Comments
        $comments = [];
        for ($i = 0; $i < $commentCount; $i++) {
            if (strlen($data) < $offset + 4) {
                break;
            }

            $commentLength = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;

            if (strlen($data) < $offset + $commentLength) {
                Log::warning(self::LOG_TAG . 'Comment extends beyond block data');
                break;
            }

            $commentString = substr($data, $offset, $commentLength);
            $offset += $commentLength;

            // Parse "FIELD=value" format
            $parts = explode('=', $commentString, 2);
            if (count($parts) === 2) {
                $field = strtoupper($parts[0]);
                $value = $parts[1];

                if (!isset($comments[$field])) {
                    $comments[$field] = [];
                }
                $comments[$field][] = $value;
            }
        }

        $this->vorbisCommentBlock = [
            'vendor' => $vendor,
            'comments' => $comments,
        ];

        Log::debug(self::LOG_TAG . 'Parsed Vorbis comments', [
            'fields' => array_keys($comments),
            'totalComments' => array_sum(array_map('count', $comments)),
        ]);
    }

    public static function parsePictureBlock(string $data): array
    {
        $offset = 0;

        // Picture type (4 bytes big endian)
        if (strlen($data) < 4) {
            return [];
        }

        $type = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;

        // MIME type length (4 bytes big endian)
        if (strlen($data) < $offset + 4) {
            return [];
        }

        $mimeLength = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;

        // MIME type
        if (strlen($data) < $offset + $mimeLength) {
            return [];
        }

        $mimeType = substr($data, $offset, $mimeLength);
        $offset += $mimeLength;

        // Description length (4 bytes big endian)
        if (strlen($data) < $offset + 4) {
            return [];
        }

        $descLength = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;

        // Description (UTF-8)
        if (strlen($data) < $offset + $descLength) {
            return [];
        }

        $description = substr($data, $offset, $descLength);
        $offset += $descLength;

        // Width, height, color depth, color count (4 bytes each)
        if (strlen($data) < $offset + 20) {
            return [];
        }

        $width = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;

        $height = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;

        $colorDepth = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;

        $colorCount = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;

        // Image data length (4 bytes big endian)
        $imageDataLength = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;

        // Image data
        if (strlen($data) < $offset + $imageDataLength) {
            return [];
        }

        $imageData = substr($data, $offset, $imageDataLength);

        Log::debug(self::LOG_TAG . 'Parsed picture block', [
            'type' => $type,
            'mimeType' => $mimeType,
            'width' => $width,
            'height' => $height,
            'imageSize' => $imageDataLength,
        ]);

        return [
            'type' => $type,
            'mimeType' => $mimeType,
            'description' => $description,
            'width' => $width,
            'height' => $height,
            'colorDepth' => $colorDepth,
            'colorCount' => $colorCount,
            'imageData' => $imageData,
            'imageSize' => $imageDataLength,
        ];
    }

    private function parseSeektableBlock(string $data): void
    {
        // Each seek point is 18 bytes:
        // - 8 bytes: Sample number (big endian)
        // - 8 bytes: Byte offset (big endian)
        // - 2 bytes: Sample count (big endian)

        $seekPoints = [];
        $offset = 0;
        $pointCount = 0;

        while (strlen($data) >= $offset + 18) {
            // Unpack the 18-byte seek point
            $sampleNumber = unpack('J', substr($data, $offset, 8))[1];
            $offset += 8;

            $byteOffset = unpack('J', substr($data, $offset, 8))[1];
            $offset += 8;

            $frameSamples = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;

            // Check for placeholder seek point (all bits set to 1)
            if ($sampleNumber === 0xFFFFFFFFFFFFFFFF &&
                $byteOffset === 0xFFFFFFFFFFFFFFFF &&
                $frameSamples === 0xFFFF) {
                break; // End of seek table
            }

            $seekPoints[] = [
                'sampleNumber' => $sampleNumber,
                'byteOffset' => $byteOffset,
                'frameSamples' => $frameSamples,
            ];

            $pointCount++;
        }

        $this->seektableBlock = [
            'seekPoints' => $seekPoints,
            'pointCount' => $pointCount,
        ];

        Log::debug(self::LOG_TAG . 'Parsed seektable', [
            'pointCount' => $pointCount,
        ]);
    }

    private function getBlockTypeName(int $type): string
    {
        $names = [
            self::BLOCK_STREAMINFO => 'STREAMINFO',
            self::BLOCK_PADDING => 'PADDING',
            self::BLOCK_APPLICATION => 'APPLICATION',
            self::BLOCK_SEEKTABLE => 'SEEKTABLE',
            self::BLOCK_VORBIS_COMMENT => 'VORBIS_COMMENT',
            self::BLOCK_CUESHEET => 'CUESHEET',
            self::BLOCK_PICTURE => 'PICTURE',
        ];

        return $names[$type] ?? "UNKNOWN({$type})";
    }

    // Public getters

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getMetadataBlocks(): array
    {
        return $this->metadataBlocks;
    }

    public function getStreamInfo(): ?array
    {
        return $this->streamInfo;
    }

    public function getVorbisCommentBlock(): ?array
    {
        return $this->vorbisCommentBlock;
    }

    public function getPictureBlocks(): array
    {
        return $this->pictureBlocks;
    }

    public function getSeektableBlock(): ?array
    {
        return $this->seektableBlock;
    }

    public function hasVorbisComments(): bool
    {
        return $this->vorbisCommentBlock !== null;
    }

    public function hasSeektable(): bool
    {
        return $this->seektableBlock !== null;
    }
}
