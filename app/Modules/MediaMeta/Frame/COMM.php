<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * COMM frame - Comments.
 *
 * The 'Comments' frame is intended for any kind of full text information
 * that does not fit in any other frame. It consists of a description and
 * the actual text. There may be more than one comment frame in each tag,
 * but only one with the same language and description.
 */
class COMM extends Frame
{
    /**
     * Constructs the COMM frame with given parameters.
     */
    public function __construct(
        protected string $text = '',
        protected string $description = '',
        protected string $language = 'eng',
        int              $encoding = Encoding::UTF8,
    )
    {
        parent::__construct('COMM', $encoding);
        $this->language = substr($language, 0, 3);
    }

    /**
     * Returns the language code.
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * Sets the language code.
     */
    public function setLanguage(string $language): self
    {
        $this->language = substr($language, 0, 3);
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
     * Returns the comment text.
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * Sets the comment text.
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

        // The next 3 bytes are language
        $this->language = substr($frameData, 1, 3);

        // Find the null terminator for the description
        $descriptionStart = 4;
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

        // Calculate text start position
        $textStart = $descriptionEnd + ($isUnicode ? 2 : 1);

        // The rest is text
        $text = substr($frameData, $textStart);

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

        // Convert text to the specified encoding
        $encodedText = match ($this->encoding) {
            Encoding::UTF16, Encoding::UTF16BE => mb_convert_encoding($this->text, 'UTF-16', 'UTF-8'),
            Encoding::UTF16LE => mb_convert_encoding($this->text, 'UTF-16LE', 'UTF-8'),
            Encoding::UTF8 => $this->text,
            default => mb_convert_encoding($this->text, 'ISO-8859-1', 'UTF-8')
        };

        // Return the encoding byte, language, description with null terminator, and text
        return chr($this->encoding) . $this->language . $encodedDescription . $nullTerminator . $encodedText;
    }
}
