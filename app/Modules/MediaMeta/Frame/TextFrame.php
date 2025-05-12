<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * Base class for ID3v2 text frames.
 *
 * Text frames are the most common frame type in ID3v2 tags.
 * They contain text information like title, artist, album, etc.
 */
class TextFrame extends Frame
{
    /**
     * Constructs the TextFrame class with given parameters.
     */
    public function __construct(
        string           $frameId,
        protected string $text = '',
        int              $encoding = Encoding::UTF8,
    )
    {
        parent::__construct($frameId, $encoding);
    }

    /**
     * Returns the text content.
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * Sets the text content.
     */
    public function setText(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    /**
     * Parses the frame data.
     */
    public function parse(string $frameData): self
    {
        // The first byte is text encoding
        $encoding = ord($frameData[0]);
        $text = substr($frameData, 1);

        // Process text based on encoding
        $this->text = match ($encoding) {
            Encoding::UTF16, Encoding::UTF16BE => mb_convert_encoding($text, 'UTF-8', 'UTF-16'),
            Encoding::UTF16LE => mb_convert_encoding($text, 'UTF-8', 'UTF-16LE'),
            Encoding::UTF8 => $text,
            default => mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1')
        };

        return $this;
    }

    /**
     * Converts the frame to binary data.
     */
    public function toBytes(): string
    {
        // Convert text to the specified encoding
        $encodedText = match ($this->encoding) {
            Encoding::UTF16, Encoding::UTF16BE => mb_convert_encoding($this->text, 'UTF-16', 'UTF-8'),
            Encoding::UTF16LE => mb_convert_encoding($this->text, 'UTF-16LE', 'UTF-8'),
            Encoding::UTF8 => $this->text,
            default => mb_convert_encoding($this->text, 'ISO-8859-1', 'UTF-8')
        };

        // Return the encoding byte followed by the encoded text
        return chr($this->encoding) . $encodedText;
    }
}
