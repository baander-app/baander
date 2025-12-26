<?php

namespace App\Modules\Metadata\Readers\Flac;

use App\Modules\Metadata\Exceptions\InvalidFlacFileException;
use App\Modules\Metadata\Readers\Flac\PictureBlocks\FlacPicture;
use Illuminate\Support\Facades\Log;

/**
 * Low-level OGG Vorbis parser
 * Extracts Vorbis comments from OGG container
 */
class OggParser
{
    private const string LOG_TAG = 'OggParser ';
    private const OGG_SIGNATURE = 'OggS';
    private const VORBIS_IDENTIFICATION = 1;
    private const VORBIS_COMMENT = 3;
    private const VORBIS_SETUP = 5;

    private string $filePath;
    private bool $isValid = false;
    private ?array $vorbisCommentBlock = null;
    private array $pictures = [];

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function parse(): void
    {
        $handle = fopen($this->filePath, 'rb');
        if ($handle === false) {
            throw InvalidFlacFileException::cannotOpenFile($this->filePath);
        }

        try {
            // Verify it's an OGG file
            $signature = fread($handle, 4);
            if ($signature !== self::OGG_SIGNATURE) {
                throw InvalidFlacFileException::parseError($this->filePath, 'Not an OGG file');
            }

            // Parse OGG pages to find Vorbis comment header
            $this->parseOggPages($handle);

            if (!$this->isValid) {
                throw InvalidFlacFileException::parseError($this->filePath, 'No Vorbis comments found');
            }

            Log::debug(self::LOG_TAG . 'Successfully parsed OGG file', [
                'file' => $this->filePath,
                'hasComments' => $this->vorbisCommentBlock !== null,
                'pictures' => count($this->pictures),
            ]);

        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . 'Parse error', [
                'file' => $this->filePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            fclose($handle);
        }
    }

    private function parseOggPages($handle): void
    {
        $foundVorbisComment = false;

        while (!feof($handle)) {
            // Read OGG page header
            $pageHeader = fread($handle, 27);
            if (strlen($pageHeader) < 27) {
                break; // End of file or incomplete page
            }

            // Verify OGG signature
            $signature = substr($pageHeader, 0, 4);
            if ($signature !== self::OGG_SIGNATURE) {
                break; // Not an OGG page
            }

            // Read segment table
            $pageSegments = ord($pageHeader[26]);
            $segmentTable = fread($handle, $pageSegments);

            // Calculate total data size
            $totalSize = array_sum(unpack('C*', $segmentTable));

            // Read page data
            $pageData = fread($handle, $totalSize);
            if (strlen($pageData) < $totalSize) {
                break; // Incomplete page data
            }

            // Skip checksum (1 byte)
            fseek($handle, 1, SEEK_CUR);

            // Parse Vorbis header packets
            $offset = 0;
            while ($offset < strlen($pageData)) {
                $packetType = ord($pageData[$offset]);

                // Check for Vorbis header packet type
                if ($offset + 7 > strlen($pageData)) {
                    break;
                }

                $vorbisSignature = substr($pageData, $offset + 1, 6);
                if ($vorbisSignature !== 'vorbis') {
                    // Not a Vorbis header, skip rest of page
                    break;
                }

                if ($packetType === self::VORBIS_COMMENT) {
                    $commentHeader = substr($pageData, $offset);
                    $this->parseVorbisCommentBlock($commentHeader);
                    $foundVorbisComment = true;
                    $this->isValid = true;
                    break; // We found what we need
                }

                // Skip to next packet (for non-comment packets)
                break;
            }

            // Continue to next page if we haven't found comments yet
            if ($foundVorbisComment) {
                break;
            }
        }
    }

    private function parseVorbisCommentBlock(string $data): void
    {
        // Skip packet type (1 byte) and 'vorbis' signature (7 bytes)
        $offset = 8;

        // Vendor string length (4 bytes, little endian)
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

        // Parse comments
        $comments = [];
        for ($i = 0; $i < $commentCount; $i++) {
            if (strlen($data) < $offset + 4) {
                break;
            }

            $commentLength = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;

            if (strlen($data) < $offset + $commentLength) {
                Log::warning(self::LOG_TAG . 'Comment extends beyond data');
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

                // Extract COVERART if present (base64 encoded image data)
                if ($field === 'COVERART' || $field === 'METADATA_BLOCK_PICTURE') {
                    $this->parseCoverArt($field, $value);
                }
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

    private function parseCoverArt(string $field, string $value): void
    {
        try {
            if ($field === 'METADATA_BLOCK_PICTURE') {
                // FLAC-style METADATA_BLOCK_PICTURE (base64 encoded)
                $pictureData = base64_decode($value, true);
                if ($pictureData) {
                    $picture = FlacParser::parsePictureBlock($pictureData);
                    if ($picture) {
                        $this->pictures[] = $picture;
                    }
                }
            } elseif ($field === 'COVERART') {
                // Legacy COVERART (base64 encoded image data)
                $imageData = base64_decode($value, true);
                if ($imageData) {
                    // Try to detect MIME type
                    $mimeType = $this->detectMimeType($imageData);

                    $this->pictures[] = [
                        'type' => FlacPicture::IMAGE_COVER_FRONT,
                        'mimeType' => $mimeType,
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
        } catch (\Exception $e) {
            Log::warning(self::LOG_TAG . 'Failed to parse cover art', [
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function detectMimeType(string $imageData): string
    {
        // Simple MIME type detection from magic bytes
        $bytes = substr($imageData, 0, 4);

        if (str_starts_with($bytes, "\xFF\xD8\xFF\xE0")) {
            return 'image/jpeg';
        }

        if (str_starts_with($bytes, "\x89\x50\x4E\x47")) {
            return 'image/png';
        }

        if (str_starts_with($bytes, 'GIF')) {
            return 'image/gif';
        }

        if (str_starts_with($bytes, 'BM')) {
            return 'image/bmp';
        }

        return 'image/unknown';
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
}
