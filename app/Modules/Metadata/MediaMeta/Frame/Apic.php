<?php

namespace App\Modules\Metadata\MediaMeta\Frame;

use App\Modules\Metadata\MediaMeta\Encoding;

/**
 * The Attached picture frame contains a picture directly related to the audio file.
 *
 * There may be several pictures attached to one file, each in their individual
 * APIC frame, but only one with the same content descriptor. There may only
 * be one picture with the same picture type.
 */
class Apic extends Frame
{
    /**
     * The list of image types.
     */
    public static array $types = [
        'Other',
        '32x32 pixels file icon (PNG only)',
        'Other file icon',
        'Cover (front)',
        'Cover (back)',
        'Leaflet page',
        'Media (e.g. label side of CD)',
        'Lead artist/lead performer/soloist',
        'Artist/performer',
        'Conductor',
        'Band/Orchestra',
        'Composer',
        'Lyricist/text writer',
        'Recording Location',
        'During recording',
        'During performance',
        'Movie/video screen capture',
        'A bright coloured fish',
        'Illustration',
        'Band/artist logotype',
        'Publisher/Studio logotype',
    ];

    protected string $mimeType = 'image/unknown';
    protected int $imageType = 0;
    protected string $description = '';
    protected string $imageData = '';
    protected int $imageSize = 0;

    /**
     * @param array|null $data {
     *     mimeType: string, The MIME type.
     *     imageType: int, The image type.
     *     description: string, The description.
     *     imageData: string The image data.
     * }
     * @param int $encoding The encoding of the frame data.
     * @return void
     */
    public function __construct(
        ?array $data = null,
        int    $encoding = Encoding::UTF8,
    )
    {
        parent::__construct('APIC', $encoding);

        if ($data === null) {
            return;
        }

        $this->mimeType = $data['mimeType'] ?? $this->mimeType;
        $this->imageType = $data['imageType'] ?? $this->imageType;
        $this->description = $data['description'] ?? $this->description;

        if (isset($data['imageData'])) {
            $this->imageData = $data['imageData'];
            $this->imageSize = strlen($this->imageData);
        }
    }

    /**
     * Returns the MIME type.
     */
    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Sets the MIME type.
     */
    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    /**
     * Returns the image type.
     */
    public function getImageType(): int
    {
        return $this->imageType;
    }

    /**
     * Sets the image type.
     */
    public function setImageType(int $imageType): self
    {
        $this->imageType = $imageType;
        return $this;
    }

    /**
     * Returns the description.
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Sets the description.
     */
    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Returns the image data.
     */
    public function getImageData(): string
    {
        return $this->imageData;
    }

    /**
     * Sets the image data.
     */
    public function setImageData(string $imageData): self
    {
        $this->imageData = $imageData;
        $this->imageSize = strlen($imageData);
        return $this;
    }

    /**
     * Returns the image size.
     */
    public function getImageSize(): int
    {
        return $this->imageSize;
    }

    /**
     * Parses the frame data.
     */
    public function parse(string $frameData): self
    {
        // The first byte is text encoding
        $encoding = ord($frameData[0]);

        // Find the null terminator for the MIME type
        $mimeTypeEnd = strpos($frameData, "\0", 1);
        $this->mimeType = substr($frameData, 1, $mimeTypeEnd - 1);

        // Next byte is picture type
        $this->imageType = ord($frameData[$mimeTypeEnd + 1]);

        // Find the null terminator for the description
        $descriptionStart = $mimeTypeEnd + 2;
        $isUnicode = in_array($encoding, [Encoding::UTF16, Encoding::UTF16BE]);
        $nullTerminator = $isUnicode ? "\0\0" : "\0";
        $descriptionEnd = strpos($frameData, $nullTerminator, $descriptionStart);
        $descriptionLength = $descriptionEnd - $descriptionStart;

        // Process description based on encoding
        $this->description = match ($encoding) {
            Encoding::UTF16, Encoding::UTF16BE => mb_convert_encoding(
                substr($frameData, $descriptionStart, $descriptionLength),
                'UTF-8',
                'UTF-16',
            ),
            Encoding::UTF16LE => mb_convert_encoding(
                substr($frameData, $descriptionStart, $descriptionLength),
                'UTF-8',
                'UTF-16LE',
            ),
            Encoding::UTF8 => substr($frameData, $descriptionStart, $descriptionLength),
            default => mb_convert_encoding(
                substr($frameData, $descriptionStart, $descriptionLength),
                'UTF-8',
                'ISO-8859-1',
            )
        };

        // Calculate image data start position
        $imageDataStart = $descriptionEnd + ($isUnicode ? 2 : 1);

        // The rest is image data
        $this->imageData = substr($frameData, $imageDataStart);
        $this->imageSize = strlen($this->imageData);

        return $this;
    }

    /**
     * Converts the frame to binary data.
     */
    public function toBytes(): string
    {
        // Determine if we're using Unicode
        $isUnicode = in_array($this->encoding, [Encoding::UTF16, Encoding::UTF16BE]);
        $nullTerminator = $isUnicode ? "\0\0" : "\0";

        // Convert description to the specified encoding
        $encodedDescription = match ($this->encoding) {
            Encoding::UTF16, Encoding::UTF16BE => mb_convert_encoding($this->description, 'UTF-16', 'UTF-8'),
            Encoding::UTF16LE => mb_convert_encoding($this->description, 'UTF-16LE', 'UTF-8'),
            Encoding::UTF8 => $this->description,
            default => mb_convert_encoding($this->description, 'ISO-8859-1', 'UTF-8')
        };

        // Start with the encoding byte
        $data = chr($this->encoding);

        // Add the MIME type with null terminator
        $data .= $this->mimeType . "\0";

        // Add the image type byte
        $data .= chr($this->imageType);

        // Add the description with null terminator
        $data .= $encodedDescription . $nullTerminator;

        // Add the image data
        $data .= $this->imageData;

        return $data;
    }
}
