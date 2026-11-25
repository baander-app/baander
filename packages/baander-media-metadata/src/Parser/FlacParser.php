<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Parser;

use Psr\Log\LoggerInterface;

/**
 * Low-level FLAC file parser.
 *
 * Reads FLAC metadata blocks directly from the binary file:
 *   - 4 bytes: "fLaC" signature
 *   - Metadata blocks (header + data):
 *       - 1 byte: Last block flag (1 bit) + Block type (7 bits)
 *       - 3 bytes: Block length (24-bit big endian)
 *       - N bytes: Block data
 *
 * The format bounds neither the number of blocks nor their total size. The parser reads only the
 * block types it decodes, at most MAX_METADATA_BYTES of them per file; it seeks past every other
 * block and past a block over the remaining budget, and stops after MAX_BLOCKS blocks. A decoded
 * block keeps at most MAX_BLOCK_ENTRIES comments or seek points, which cost about twenty times
 * their bytes in memory. What was read before a limit stays in the result.
 */
final class FlacParser
{
    public const int MAX_METADATA_BYTES = 33_554_432;
    public const int MAX_BLOCKS = 1_024;
    public const int MAX_BLOCK_ENTRIES = 65_536;

    private const string FLAC_SIGNATURE = "fLaC";

    private const int BLOCK_STREAMINFO = 0;
    private const int BLOCK_PADDING = 1;
    private const int BLOCK_APPLICATION = 2;
    private const int BLOCK_SEEKTABLE = 3;
    private const int BLOCK_VORBIS_COMMENT = 4;
    private const int BLOCK_CUESHEET = 5;
    private const int BLOCK_PICTURE = 6;

    /** The blocks the parser reads and decodes; it seeks past every other one. */
    private const array DECODED_BLOCKS = [self::BLOCK_STREAMINFO, self::BLOCK_SEEKTABLE, self::BLOCK_VORBIS_COMMENT, self::BLOCK_PICTURE];

    /** @var list<array{type: string, typeCode: int, isLast: bool, length: int}> */
    private array $metadataBlocks = [];
    private int $bytesRemaining = self::MAX_METADATA_BYTES;
    private bool $truncated = false;
    /** @var array{sampleRate: int, channels: int, bitsPerSample: int, totalSamples: int}|null */
    private ?array $streamInfo = null;
    /** @var array{vendor: string, comments: array<string, list<string>>}|null */
    private ?array $vorbisCommentBlock = null;
    /** @var list<array{type: int, mimeType: string, description: string, width: int, height: int, colorDepth: int, colorCount: int, imageData: string, imageSize: int}|array{}> */
    private array $pictureBlocks = [];
    /** @var array{seekPoints: list<array{sampleNumber: int, byteOffset: int, frameSamples: int}>, pointCount: int}|null */
    private ?array $seektableBlock = null;
    private bool $isValid = false;

    public function __construct(
        private readonly string $filePath,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function parse(): void
    {
        $handle = @fopen($this->filePath, 'rb');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open FLAC file: {$this->filePath}");
        }

        try {
            $this->validateSignature($handle);
            $this->parseMetadataBlocks($handle);
            $this->isValid = true;

            if ($this->truncated) {
                $this->logger->warning('FlacParser: metadata over the parse limits; the blocks past them were skipped', [
                    'path' => $this->filePath,
                    'blocks' => count($this->metadataBlocks),
                ]);
            }

            $this->logger->debug('FlacParser: parsed FLAC file', [
                'path' => $this->filePath,
                'blocks' => count($this->metadataBlocks),
                'has_vorbis_comments' => $this->vorbisCommentBlock !== null,
                'has_pictures' => count($this->pictureBlocks) > 0,
                'has_seektable' => $this->seektableBlock !== null,
            ]);
        } finally {
            fclose($handle);
        }
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    /** @return list<array{type: string, typeCode: int, isLast: bool, length: int}> */
    public function getMetadataBlocks(): array
    {
        return $this->metadataBlocks;
    }

    /** @return array{sampleRate: int, channels: int, bitsPerSample: int, totalSamples: int}|null */
    public function getStreamInfo(): ?array
    {
        return $this->streamInfo;
    }

    /** @return array{vendor: string, comments: array<string, list<string>>}|null */
    public function getVorbisCommentBlock(): ?array
    {
        return $this->vorbisCommentBlock;
    }

    /** @return list<array{type: int, mimeType: string, description: string, width: int, height: int, colorDepth: int, colorCount: int, imageData: string, imageSize: int}|array{}> */
    public function getPictureBlocks(): array
    {
        return $this->pictureBlocks;
    }

    /** @return array{seekPoints: list<array{sampleNumber: int, byteOffset: int, frameSamples: int}>, pointCount: int}|null */
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

    /**
     * Parse a METADATA_BLOCK_PICTURE payload (static for reuse by OggParser).
     *
     * @return array{type: int, mimeType: string, description: string, width: int, height: int, colorDepth: int, colorCount: int, imageData: string, imageSize: int}|array{}
     */
    public static function parsePictureBlock(string $data): array
    {
        $offset = 0;

        if (strlen($data) < 4) {
            return [];
        }

        $type = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;

        if (strlen($data) < $offset + 4) {
            return [];
        }

        $mimeLength = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;

        if (strlen($data) < $offset + $mimeLength) {
            return [];
        }

        $mimeType = substr($data, $offset, $mimeLength);
        $offset += $mimeLength;

        if (strlen($data) < $offset + 4) {
            return [];
        }

        $descLength = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;

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

        if (strlen($data) < $offset + 4) {
            return [];
        }

        $imageDataLength = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;

        if (strlen($data) < $offset + $imageDataLength) {
            return [];
        }

        $imageData = substr($data, $offset, $imageDataLength);

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

    // ---- Private methods ----

    /** @param resource $handle */
    private function validateSignature($handle): void
    {
        $signature = fread($handle, 4);

        if ($signature !== self::FLAC_SIGNATURE) {
            throw new \RuntimeException("Invalid FLAC signature in: {$this->filePath}");
        }
    }

    /**
     * @param resource $handle
     */
    private function parseMetadataBlocks($handle): void
    {
        $isLast = false;

        while (!$isLast) {
            if (count($this->metadataBlocks) === self::MAX_BLOCKS) {
                $this->truncated = true;

                return;
            }

            $header = $this->readBlockHeader($handle);
            $isLast = $header['isLast'];
            $blockType = $header['type'];
            $blockLength = $header['length'];

            $this->logger->debug('FlacParser: reading metadata block', [
                'block' => count($this->metadataBlocks),
                'type' => $this->getBlockTypeName($blockType),
                'length' => $blockLength,
                'is_last' => $isLast,
            ]);

            $this->metadataBlocks[] = [
                'type' => $this->getBlockTypeName($blockType),
                'typeCode' => $blockType,
                'isLast' => $isLast,
                'length' => $blockLength,
            ];

            if (!in_array($blockType, self::DECODED_BLOCKS, true)) {
                $this->skip($handle, $blockLength);

                continue;
            }

            if ($blockLength > $this->bytesRemaining) {
                $this->truncated = true;
                $this->skip($handle, $blockLength);

                continue;
            }

            $this->bytesRemaining -= $blockLength;
            $blockData = $this->read($handle, $blockLength);

            match ($blockType) {
                self::BLOCK_STREAMINFO => $this->parseStreamInfo($blockData),
                self::BLOCK_VORBIS_COMMENT => $this->parseVorbisCommentBlock($blockData),
                self::BLOCK_PICTURE => $this->pictureBlocks[] = self::parsePictureBlock($blockData),
                self::BLOCK_SEEKTABLE => $this->parseSeektableBlock($blockData),
            };
        }
    }

    /** @param resource $handle */
    private function read($handle, int $length): string
    {
        // fread() refuses a length of 0, and an empty block is valid.
        $data = $length === 0 ? '' : fread($handle, $length);

        if ($data === false || strlen($data) !== $length) {
            throw new \RuntimeException(
                "Failed to read FLAC block data (expected {$length} bytes) in: {$this->filePath}",
            );
        }

        return $data;
    }

    /** @param resource $handle */
    private function skip($handle, int $length): void
    {
        if (fseek($handle, $length, SEEK_CUR) !== 0) {
            throw new \RuntimeException("Failed to skip a FLAC block of {$length} bytes in: {$this->filePath}");
        }
    }

    /**
     * @param resource $handle
     * @return array{isLast: bool, type: int, length: int}
     */
    private function readBlockHeader($handle): array
    {
        $header = fread($handle, 4);

        if ($header === false || strlen($header) !== 4) {
            throw new \RuntimeException("Failed to read FLAC block header in: {$this->filePath}");
        }

        $headerByte = ord($header[0]);
        $length = unpack('N', "\x00" . substr($header, 1))[1];

        return ['isLast' => ($headerByte & 0x80) !== 0, 'type' => $headerByte & 0x7F, 'length' => $length];
    }

    private function parseStreamInfo(string $data): void
    {
        if (strlen($data) < 34) {
            return;
        }

        $unpacked = unpack('Nupper/Nlower', substr($data, 10, 8));

        $upper = $unpacked['upper'];
        $lower = $unpacked['lower'];

        $sampleRate = ($upper >> 12) & 0xFFFFF;
        $channels = (($upper >> 9) & 0x7) + 1;
        $bitsPerSample = (($upper >> 4) & 0x1F) + 1;
        $totalSamples = (($upper & 0xF) << 32) | $lower;

        $this->streamInfo = [
            'sampleRate' => $sampleRate,
            'channels' => $channels,
            'bitsPerSample' => $bitsPerSample,
            'totalSamples' => $totalSamples,
        ];

        $this->logger->debug('FlacParser: parsed STREAMINFO', $this->streamInfo);
    }

    private function parseVorbisCommentBlock(string $data): void
    {
        $offset = 0;

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

        if (strlen($data) < $offset + 4) {
            return;
        }

        $commentCount = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        $this->logger->debug('FlacParser: parsing Vorbis comments', [
            'vendor' => $vendor,
            'comment_count' => $commentCount,
        ]);

        $comments = [];

        for ($i = 0; $i < min($commentCount, self::MAX_BLOCK_ENTRIES); $i++) {
            if (strlen($data) < $offset + 4) {
                break;
            }

            $commentLength = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;

            if (strlen($data) < $offset + $commentLength) {
                break;
            }

            $commentString = substr($data, $offset, $commentLength);
            $offset += $commentLength;

            $parts = explode('=', $commentString, 2);

            if (count($parts) === 2) {
                $field = strtoupper($parts[0]);
                $comments[$field][] = $parts[1];
            }
        }

        $this->vorbisCommentBlock = [
            'vendor' => $vendor,
            'comments' => $comments,
        ];

        $this->logger->debug('FlacParser: parsed Vorbis comments', [
            'fields' => array_keys($comments),
        ]);
    }

    private function parseSeektableBlock(string $data): void
    {
        $seekPoints = [];
        $offset = 0;

        while (strlen($data) >= $offset + 18 && count($seekPoints) < self::MAX_BLOCK_ENTRIES) {
            $sampleNumber = unpack('J', substr($data, $offset, 8))[1];
            $offset += 8;
            $byteOffset = unpack('J', substr($data, $offset, 8))[1];
            $offset += 8;
            $frameSamples = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;

            // Placeholder seek point (all bits set to 1)
            if ($sampleNumber === 0xFFFFFFFFFFFFFFFF
                && $byteOffset === 0xFFFFFFFFFFFFFFFF
                && $frameSamples === 0xFFFF
            ) {
                break;
            }

            $seekPoints[] = [
                'sampleNumber' => $sampleNumber,
                'byteOffset' => $byteOffset,
                'frameSamples' => $frameSamples,
            ];
        }

        $this->seektableBlock = [
            'seekPoints' => $seekPoints,
            'pointCount' => count($seekPoints),
        ];
    }

    private function getBlockTypeName(int $type): string
    {
        return match ($type) {
            self::BLOCK_STREAMINFO => 'STREAMINFO',
            self::BLOCK_PADDING => 'PADDING',
            self::BLOCK_APPLICATION => 'APPLICATION',
            self::BLOCK_SEEKTABLE => 'SEEKTABLE',
            self::BLOCK_VORBIS_COMMENT => 'VORBIS_COMMENT',
            self::BLOCK_CUESHEET => 'CUESHEET',
            self::BLOCK_PICTURE => 'PICTURE',
            default => "UNKNOWN({$type})",
        };
    }
}
