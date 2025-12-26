<?php

namespace App\Modules\Metadata\Readers\Flac;

use App\Baander;
use App\Modules\Metadata\Contracts\Flac\FlacWriterInterface;
use App\Modules\Metadata\Exceptions\InvalidFlacFileException;
use App\Modules\Metadata\Readers\Flac\PictureBlocks\FlacPicture;
use Illuminate\Support\Facades\Log;

/**
 * FLAC metadata writer
 * Writes Vorbis comments and pictures to FLAC files
 */
class FlacWriter implements FlacWriterInterface
{
    private const string LOG_TAG = 'FlacWriter ';

    private string $filePath;
    private array $fields = [];
    private array $fieldsToRemove = [];
    private array $pictures = [];
    private bool $clearAllPictures = false;
    private array $picturesToRemoveByType = [];
    private ?FlacParser $parser = null;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function setField(string $field, string|array $value): self
    {
        $normalizedField = strtoupper(trim($field));

        // Convert single value to array for consistent handling
        $this->fields[$normalizedField] = is_array($value) ? $value : [$value];

        // Remove from removal list if it was there
        unset($this->fieldsToRemove[$normalizedField]);

        return $this;
    }

    public function setFields(array $fields): self
    {
        foreach ($fields as $field => $value) {
            $this->setField($field, $value);
        }

        return $this;
    }

    public function removeField(string $field): self
    {
        $normalizedField = strtoupper(trim($field));
        $this->fieldsToRemove[$normalizedField] = true;
        unset($this->fields[$normalizedField]);

        return $this;
    }

    public function setTitle(string $title): self
    {
        return $this->setField('TITLE', $title);
    }

    public function setArtist(string|array $artist): self
    {
        return $this->setField('ARTIST', $artist);
    }

    public function setAlbum(string $album): self
    {
        return $this->setField('ALBUM', $album);
    }

    public function setGenre(string $genre): self
    {
        return $this->setField('GENRE', $genre);
    }

    public function setYear(string $year): self
    {
        // Use DATE field (standard Vorbis)
        return $this->setField('DATE', $year);
    }

    public function setTrackNumber(int $track, ?int $total = null): self
    {
        $value = (string)$track;
        if ($total !== null) {
            $value .= '/' . $total;
        }

        return $this->setField('TRACKNUMBER', $value);
    }

    public function setDiscNumber(int $disc, ?int $total = null): self
    {
        $value = (string)$disc;
        if ($total !== null) {
            $value .= '/' . $total;
        }

        return $this->setField('DISCNUMBER', $value);
    }

    public function setComment(string $comment): self
    {
        return $this->setField('COMMENT', $comment);
    }

    public function setPicture(object $picture, ?int $type = null): self
    {
        $picType = $type ?? ($picture->getImageType() ?? FlacPicture::IMAGE_OTHER);
        $this->pictures[] = [
            'picture' => $picture,
            'type' => $picType,
        ];

        return $this;
    }

    public function clearPictures(): self
    {
        $this->clearAllPictures = true;
        $this->pictures = [];
        $this->picturesToRemoveByType = [];

        return $this;
    }

    public function removePicturesByType(int $type): self
    {
        $this->picturesToRemoveByType[$type] = true;

        return $this;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function write(bool $backup = true): bool
    {
        // Create backup if requested
        if ($backup) {
            $this->createBackup();
        }

        try {
            // Read existing FLAC file
            $this->parser = new FlacParser($this->filePath);
            $this->parser->parse();

            // Get existing metadata blocks
            $blocks = $this->parser->getMetadataBlocks();

            // Build new metadata blocks
            $newBlocks = $this->buildNewMetadataBlocks($blocks);

            // Write new FLAC file
            $this->writeFlacFile($newBlocks);

            Log::info(self::LOG_TAG . 'Successfully wrote FLAC metadata', [
                'file' => $this->filePath,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . 'Failed to write FLAC metadata', [
                'file' => $this->filePath,
                'error' => $e->getMessage(),
            ]);

            throw new InvalidFlacFileException(
                "Failed to write FLAC file: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function createBackup(): void
    {
        $backupPath = $this->filePath . '.bak';

        if (!copy($this->filePath, $backupPath)) {
            Log::warning(self::LOG_TAG . 'Failed to create backup', [
                'file' => $this->filePath,
                'backup' => $backupPath,
            ]);
        }
    }

    /**
     * Build new metadata blocks with updated Vorbis comments and pictures
     *
     * @param array $existingBlocks
     * @return array
     */
    private function buildNewMetadataBlocks(array $existingBlocks): array
    {
        $newBlocks = [];
        $existingVorbisComments = [];
        $existingPictures = [];

        // Get VORBIS_COMMENT data from parser
        $vorbisBlock = $this->parser->getVorbisCommentBlock();
        if ($vorbisBlock) {
            $existingVorbisComments = $vorbisBlock['comments'] ?? [];
        }

        // Get PICTURE data from parser
        $parserPictures = $this->parser->getPictureBlocks();
        foreach ($parserPictures as $picture) {
            if (!$this->clearAllPictures) {
                $picType = $picture['type'] ?? 0;
                if (!isset($this->picturesToRemoveByType[$picType])) {
                    $existingPictures[] = $picture;
                }
            }
        }

        // Merge existing comments with new/updated fields
        $mergedComments = $this->mergeVorbisComments($existingVorbisComments);

        // Build VORBIS_COMMENT block data
        $vorbisCommentData = $this->buildVorbisCommentBlock($mergedComments);

        // Merge existing pictures with new pictures
        $mergedPictures = array_merge($existingPictures, array_column($this->pictures, 'picture'));

        // Build blocks (skip VORBIS_COMMENT and PICTURE blocks, we'll add them back)
        foreach ($existingBlocks as $index => $block) {
            if ($block['type'] !== 'VORBIS_COMMENT' && $block['type'] !== 'PICTURE') {
                $newBlocks[] = $block;
            }
        }

        // Insert VORBIS_COMMENT after STREAMINFO (first block)
        // This is the standard position
        array_splice($newBlocks, 1, 0, [[
            'type' => 'VORBIS_COMMENT',
            'data' => $vorbisCommentData,
            'isLast' => false,
        ]]);

        // Insert PICTURE blocks after VORBIS_COMMENT
        foreach ($mergedPictures as $picture) {
            $newBlocks[] = [
                'type' => 'PICTURE',
                'data' => $this->buildPictureBlock($picture),
                'isLast' => false,
            ];
        }

        // Mark last block
        $lastIndex = count($newBlocks) - 1;
        $newBlocks[$lastIndex]['isLast'] = true;

        return $newBlocks;
    }

    /**
     * Merge existing Vorbis comments with new/updated fields
     *
     * @param array $existingComments
     * @return array
     */
    private function mergeVorbisComments(array $existingComments): array
    {
        $merged = $existingComments;

        // Remove fields marked for removal
        foreach ($this->fieldsToRemove as $field => $true) {
            unset($merged[$field]);
        }

        // Add/update new fields
        foreach ($this->fields as $field => $values) {
            $merged[$field] = $values;
        }

        return $merged;
    }

    /**
     * Build VORBIS_COMMENT block binary data
     *
     * @param array $comments
     * @return string
     */
    private function buildVorbisCommentBlock(array $comments): string
    {
        $data = '';

        // Vendor string (little-endian length + string)
        $vendor = 'Baander ' . Baander::VERSION;
        $vendorLength = strlen($vendor);
        $data .= pack('V', $vendorLength);
        $data .= $vendor;

        // Comment count (little-endian)
        $commentCount = 0;
        foreach ($comments as $field => $values) {
            $commentCount += count($values);
        }
        $data .= pack('V', $commentCount);

        // Comments (each: little-endian length + "FIELD=value" string)
        foreach ($comments as $field => $values) {
            foreach ($values as $value) {
                $commentString = "{$field}={$value}";
                $commentLength = strlen($commentString);
                $data .= pack('V', $commentLength);
                $data .= $commentString;
            }
        }

        return $data;
    }

    /**
     * Build PICTURE block binary data
     *
     * @param object|array $picture
     * @return string
     */
    private function buildPictureBlock(object|array $picture): string
    {
        // If it's a FlacPicture, use its properties
        if ($picture instanceof FlacPicture) {
            $type = $picture->getImageType();
            $mimeType = $picture->getMimeType();
            $description = $picture->getDescription();
            $width = $picture->getWidth();
            $height = $picture->getHeight();
            $colorDepth = $picture->getColorDepth();
            $colorCount = $picture->getColorCount();
            $imageData = $picture->getImageData();
        } else {
            // Assume array format from FlacParser
            $pic = (array)$picture;
            $type = $pic['type'] ?? FlacPicture::IMAGE_OTHER;
            $mimeType = $pic['mimeType'] ?? 'image/jpeg';
            $description = $pic['description'] ?? '';
            $width = $pic['width'] ?? 0;
            $height = $pic['height'] ?? 0;
            $colorDepth = $pic['colorDepth'] ?? 0;
            $colorCount = $pic['colorCount'] ?? 0;
            $imageData = $pic['imageData'] ?? '';
        }

        $data = '';

        // All big-endian except for picture-specific quirks

        // Picture type (4 bytes big-endian)
        $data .= pack('N', $type);

        // MIME type length (4 bytes big-endian) + MIME type string
        $mimeTypeLength = strlen($mimeType);
        $data .= pack('N', $mimeTypeLength);
        $data .= $mimeType;

        // Description length (4 bytes big-endian) + description string (UTF-8)
        $descriptionLength = strlen($description);
        $data .= pack('N', $descriptionLength);
        $data .= $description;

        // Width, height, color depth, color count (all 4 bytes big-endian)
        $data .= pack('N', $width);
        $data .= pack('N', $height);
        $data .= pack('N', $colorDepth);
        $data .= pack('N', $colorCount);

        // Image data length (4 bytes big-endian) + image data
        $imageDataLength = strlen($imageData);
        $data .= pack('N', $imageDataLength);
        $data .= $imageData;

        return $data;
    }

    /**
     * Write the FLAC file with new metadata blocks
     *
     * @param array $blocks
     * @return void
     */
    private function writeFlacFile(array $blocks): void
    {
        // Open the original file to get audio data
        $originalHandle = fopen($this->filePath, 'rb');
        if (!$originalHandle) {
            throw new \Exception("Failed to open original file for reading");
        }

        // Find where audio data starts
        $audioDataStart = $this->findAudioDataStart($originalHandle);

        // Create temp file for writing
        $tempPath = $this->filePath . '.tmp';
        $tempHandle = fopen($tempPath, 'wb');
        if (!$tempHandle) {
            fclose($originalHandle);
            throw new \Exception("Failed to create temp file for writing");
        }

        try {
            // Write FLAC signature
            fwrite($tempHandle, 'fLaC');

            // Write metadata blocks
            $blockCount = count($blocks);
            foreach ($blocks as $index => $block) {
                $isLast = ($index === $blockCount - 1);

                // Build block header
                $blockType = $this->getBlockTypeCode($block['type']);
                $blockData = $block['data'] ?? '';
                $blockLength = strlen($blockData);

                $headerByte = ($isLast ? 0x80 : 0x00) | ($blockType & 0x7F);
                $lengthBytes = substr(pack('N', $blockLength), 1, 3); // Big-endian, 3 bytes

                fwrite($tempHandle, chr($headerByte) . $lengthBytes);
                fwrite($tempHandle, $blockData);
            }

            // Copy audio data in chunks (8MB at a time to avoid memory issues)
            fseek($originalHandle, $audioDataStart);
            $chunkSize = 8 * 1024 * 1024; // 8MB chunks

            while (!feof($originalHandle)) {
                $chunk = fread($originalHandle, $chunkSize);
                if ($chunk === false || strlen($chunk) === 0) {
                    break;
                }
                fwrite($tempHandle, $chunk);
            }

            fflush($tempHandle);
            fclose($tempHandle);
            fclose($originalHandle);

            // Replace original file with temp file
            if (!rename($tempPath, $this->filePath)) {
                throw new \Exception("Failed to replace original file with updated file");
            }

        } catch (\Exception $e) {
            // Clean up resources
            if (is_resource($tempHandle)) {
                fclose($tempHandle);
            }
            if (is_resource($originalHandle)) {
                fclose($originalHandle);
            }
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            throw $e;
        }
    }

    /**
     * Find the byte position where audio data starts (after all metadata blocks)
     *
     * @param resource $handle
     * @return int
     */
    private function findAudioDataStart($handle): int
    {
        $signature = fread($handle, 4);
        if ($signature !== 'fLaC') {
            throw new InvalidFlacFileException("Invalid FLAC signature");
        }

        // Skip all metadata blocks to find audio data start
        while (!feof($handle)) {
            $blockHeader = fread($handle, 4);
            if (strlen($blockHeader) < 4) {
                break;
            }

            $lastFlag = ord($blockHeader[0]) & 0x80;
            $blockLength = unpack('N', "\x00" . substr($blockHeader, 1, 3))[1];

            // Skip block data
            fseek($handle, $blockLength, SEEK_CUR);

            if ($lastFlag) {
                break; // Last metadata block, next is audio data
            }
        }

        return ftell($handle);
    }

    /**
     * Get block type code for block type name
     *
     * @param string $typeName
     * @return int
     */
    private function getBlockTypeCode(string $typeName): int
    {
        $types = [
            'STREAMINFO' => 0,
            'PADDING' => 1,
            'APPLICATION' => 2,
            'SEEKTABLE' => 3,
            'VORBIS_COMMENT' => 4,
            'CUESHEET' => 5,
            'PICTURE' => 6,
        ];

        return $types[$typeName] ?? 0; // Default to STREAMINFO if unknown
    }
}
