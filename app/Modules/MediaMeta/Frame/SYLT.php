<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * SYLT frame - Synchronized lyric/text.
 *
 * The 'Synchronized lyric/text' frame is another way of incorporating the lyrics in the audio file,
 * but this time in sync with the audio. It might also be used to describe events occurring on a
 * stage or on the screen in sync with the audio.
 */
class SYLT extends Frame
{
    /**
     * Content type constants.
     */
    public const int TYPE_OTHER = 0;
    public const int TYPE_LYRICS = 1;
    public const int TYPE_TEXT_TRANSCRIPTION = 2;
    public const int TYPE_MOVEMENT_PART_NAME = 3;
    public const int TYPE_EVENTS = 4;
    public const int TYPE_CHORD = 5;
    public const int TYPE_TRIVIA = 6;
    public const int TYPE_URLS_TO_WEBPAGES = 7;
    public const int TYPE_URLS_TO_IMAGES = 8;

    /**
     * Time format constants.
     */
    public const int FORMAT_MPEG_FRAMES = 1;
    public const int FORMAT_MILLISECONDS = 2;

    /**
     * Constructs the SYLT frame with given parameters.
     */
    public function __construct(
        protected array  $events = [],
        protected string $description = '',
        protected string $language = 'eng',
        protected int    $format = self::FORMAT_MILLISECONDS,
        protected int    $type = self::TYPE_LYRICS,
        int              $encoding = Encoding::UTF8,
    )
    {
        parent::__construct('SYLT', $encoding);
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
     * Returns the time format.
     */
    public function getFormat(): int
    {
        return $this->format;
    }

    /**
     * Sets the time format.
     */
    public function setFormat(int $format): self
    {
        $this->format = $format;
        return $this;
    }

    /**
     * Returns the content type.
     */
    public function getType(): int
    {
        return $this->type;
    }

    /**
     * Sets the content type.
     */
    public function setType(int $type): self
    {
        $this->type = $type;
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
     * Returns the events with their timestamps.
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * Sets the events with their timestamps.
     */
    public function setEvents(array $events): self
    {
        $this->events = $events;
        ksort($this->events);
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

        // The next byte is time format
        $this->format = ord($frameData[4]);

        // The next byte is content type
        $this->type = ord($frameData[5]);

        // Find the null terminator for the description
        $descriptionStart = 6;
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

        // Parse the events
        $offset = $descriptionEnd + ($isUnicode ? 2 : 1);
        $this->events = [];

        while ($offset < strlen($frameData)) {
            // Find the null terminator for the syllable
            $syllableStart = $offset;
            $syllableEnd = strpos($frameData, $nullTerminator, $syllableStart);

            if ($syllableEnd === false) {
                break;
            }

            $syllableLength = $syllableEnd - $syllableStart;

            // Process syllable based on encoding
            $syllable = match ($encoding) {
                Encoding::UTF16, Encoding::UTF16BE => mb_convert_encoding(
                    substr($frameData, $syllableStart, $syllableLength),
                    'UTF-8',
                    'UTF-16',
                ),
                Encoding::UTF16LE => mb_convert_encoding(
                    substr($frameData, $syllableStart, $syllableLength),
                    'UTF-8',
                    'UTF-16LE',
                ),
                Encoding::UTF8 => substr($frameData, $syllableStart, $syllableLength),
                default => mb_convert_encoding(
                    substr($frameData, $syllableStart, $syllableLength),
                    'UTF-8',
                    'ISO-8859-1',
                )
            };

            // Move offset past the null terminator
            $offset = $syllableEnd + ($isUnicode ? 2 : 1);

            // Read the timestamp (4 bytes)
            if ($offset + 4 > strlen($frameData)) {
                break;
            }

            $timestamp = (
                ord($frameData[$offset]) << 24 |
                ord($frameData[$offset + 1]) << 16 |
                ord($frameData[$offset + 2]) << 8 |
                ord($frameData[$offset + 3])
            );
            $offset += 4;

            // Store the event
            $this->events[$timestamp] = $syllable;
        }

        // Sort events by timestamp
        ksort($this->events);

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

        // Start with the encoding byte, language, format, and type
        $data = chr($this->encoding) . $this->language . chr($this->format) . chr($this->type);

        // Add the description with null terminator
        $data .= $encodedDescription . $nullTerminator;

        // Add each event (syllable with null terminator followed by timestamp)
        foreach ($this->events as $timestamp => $syllable) {
            // Convert syllable to the specified encoding
            $encodedSyllable = match ($this->encoding) {
                Encoding::UTF16, Encoding::UTF16BE => mb_convert_encoding($syllable, 'UTF-16', 'UTF-8'),
                Encoding::UTF16LE => mb_convert_encoding($syllable, 'UTF-16LE', 'UTF-8'),
                Encoding::UTF8 => $syllable,
                default => mb_convert_encoding($syllable, 'ISO-8859-1', 'UTF-8')
            };

            // Add the syllable with null terminator
            $data .= $encodedSyllable . $nullTerminator;

            // Add the timestamp (4 bytes, big-endian)
            $data .= chr(($timestamp >> 24) & 0xFF);
            $data .= chr(($timestamp >> 16) & 0xFF);
            $data .= chr(($timestamp >> 8) & 0xFF);
            $data .= chr($timestamp & 0xFF);
        }

        return $data;
    }
}
