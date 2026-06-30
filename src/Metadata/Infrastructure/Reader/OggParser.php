<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Reader;

use Psr\Log\LoggerInterface;

/**
 * Low-level OGG Vorbis parser.
 *
 * Extracts Vorbis comments from the OGG container by parsing
 * OGG pages and locating the comment header packet.
 */
final class OggParser
{
    private const string OGG_SIGNATURE = 'OggS';
    private const int VORBIS_COMMENT = 3;

    private bool $isValid = false;
    private ?array $vorbisCommentBlock = null;
    /** @var list<array> */
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
            throw new \RuntimeException("Cannot open OGG file: {$this->filePath}");
        }

        try {
            $signature = fread($handle, 4);

            if ($signature !== self::OGG_SIGNATURE) {
                throw new \RuntimeException("Not an OGG file: {$this->filePath}");
            }

            // Rewind so parseOggPages reads the full page header from byte 0
            fseek($handle, 0);

            $this->parseOggPages($handle);

            if (!$this->isValid) {
                throw new \RuntimeException("No Vorbis comments found in: {$this->filePath}");
            }

            $this->logger->debug('OggParser: parsed OGG file', [
                'path' => $this->filePath,
                'has_comments' => $this->vorbisCommentBlock !== null,
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

    public function getVorbisCommentBlock(): ?array
    {
        return $this->vorbisCommentBlock;
    }

    public function getPictures(): array
    {
        return $this->pictures;
    }

    public function hasVorbisComments(): bool
    {
        return $this->vorbisCommentBlock !== null;
    }

    // ---- Private methods ----

    /**
     * @param resource $handle
     */
    private function parseOggPages($handle): void
    {
        $foundVorbisComment = false;

        while (!feof($handle)) {
            $pageHeader = fread($handle, 27);
            if (strlen($pageHeader) < 27) {
                break;
            }

            if (substr($pageHeader, 0, 4) !== self::OGG_SIGNATURE) {
                break;
            }

            $pageSegments = ord($pageHeader[26]);
            $segmentTable = fread($handle, $pageSegments);
            $totalSize = array_sum(unpack('C*', $segmentTable));

            $pageData = fread($handle, $totalSize);
            if (strlen($pageData) < $totalSize) {
                break;
            }

            $offset = 0;

            while ($offset < strlen($pageData)) {
                if ($offset + 7 > strlen($pageData)) {
                    break;
                }

                $packetType = ord($pageData[$offset]);
                $signature = substr($pageData, $offset + 1, 6);

                // Vorbis: \x03vorbis (7 bytes), Opus: \x03OpusTags (8 bytes)
                $isVorbis = $signature === 'vorbis';
                $isOpus = str_starts_with(substr($pageData, $offset + 1, 7), 'OpusTags');

                if (!$isVorbis && !$isOpus) {
                    break;
                }

                if ($packetType === self::VORBIS_COMMENT || $isOpus) {
                    $headerSize = $isVorbis ? 7 : 8; // skip \x03vorbis or \x03OpusTags
                    $commentData = substr($pageData, $offset + $headerSize);
                    $this->parseVorbisCommentData($commentData);
                    $foundVorbisComment = true;
                    $this->isValid = true;
                    break 2;
                }

                break;
            }

            if ($foundVorbisComment) {
                break;
            }
        }
    }

    private function parseVorbisCommentBlock(string $data): void
    {
        $this->parseVorbisCommentData(substr($data, 7));
    }

    private function parseVorbisCommentData(string $data): void
    {
        $offset = 0;

        if (strlen($data) < $offset + 4) {
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

        $this->logger->debug('OggParser: parsing Vorbis comments', [
            'vendor' => $vendor,
            'comment_count' => $commentCount,
        ]);

        $comments = [];

        for ($i = 0; $i < $commentCount; $i++) {
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

                if ($field === 'COVERART' || $field === 'METADATA_BLOCK_PICTURE') {
                    $this->parseCoverArt($field, $parts[1]);
                }
            }
        }

        $this->vorbisCommentBlock = [
            'vendor' => $vendor,
            'comments' => $comments,
        ];

        $this->logger->debug('OggParser: parsed Vorbis comments', [
            'fields' => array_keys($comments),
        ]);
    }

    private function parseCoverArt(string $field, string $value): void
    {
        try {
            if ($field === 'METADATA_BLOCK_PICTURE') {
                $pictureData = base64_decode($value, true);

                if ($pictureData !== false) {
                    $picture = FlacParser::parsePictureBlock($pictureData);

                    if ($picture !== []) {
                        $this->pictures[] = $picture;
                    }
                }
            } elseif ($field === 'COVERART') {
                $imageData = base64_decode($value, true);

                if ($imageData !== false) {
                    $this->pictures[] = [
                        'type' => 3, // Cover front
                        'mimeType' => $this->detectMimeType($imageData),
                        'description' => '',
                        'width' => 0,
                        'height' => 0,
                        'colorDepth' => 0,
                        'colorCount' => 0,
                        'imageData' => $imageData,
                        'imageSize' => strlen($imageData),
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('OggParser: failed to parse cover art', [
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function detectMimeType(string $imageData): string
    {
        $bytes = substr($imageData, 0, 4);

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($bytes, "\x89\x50\x4E\x47")) {
            return 'image/png';
        }

        if (str_starts_with($bytes, 'GIF')) {
            return 'image/gif';
        }

        return 'image/unknown';
    }
}
