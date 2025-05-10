<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * ETCO frame - Event timing codes.
 *
 * The 'Event timing codes' frame allows synchronization with key events in the audio.
 * It contains a time format byte and a list of events with their timestamps.
 */
class ETCO extends Frame
{
    /**
     * Event type constants.
     */
    public const int EVENT_PADDING = 0x00;
    public const int EVENT_END_OF_INITIAL_SILENCE = 0x01;
    public const int EVENT_INTRO_START = 0x02;
    public const int EVENT_MAIN_PART_START = 0x03;
    public const int EVENT_OUTRO_START = 0x04;
    public const int EVENT_OUTRO_END = 0x05;
    public const int EVENT_VERSE_START = 0x06;
    public const int EVENT_REFRAIN_START = 0x07;
    public const int EVENT_INTERLUDE_START = 0x08;
    public const int EVENT_THEME_START = 0x09;
    public const int EVENT_VARIATION_START = 0x0A;
    public const int EVENT_KEY_CHANGE = 0x0B;
    public const int EVENT_TIME_CHANGE = 0x0C;
    public const int EVENT_MOMENTARY_UNWANTED_NOISE = 0x0D;
    public const int EVENT_SUSTAINED_NOISE = 0x0E;
    public const int EVENT_SUSTAINED_NOISE_END = 0x0F;
    public const int EVENT_INTRO_END = 0x10;
    public const int EVENT_MAIN_PART_END = 0x11;
    public const int EVENT_VERSE_END = 0x12;
    public const int EVENT_REFRAIN_END = 0x13;
    public const int EVENT_THEME_END = 0x14;
    public const int EVENT_PROFANITY = 0x15;
    public const int EVENT_PROFANITY_END = 0x16;

    /**
     * Time format constants.
     */
    public const int FORMAT_MPEG_FRAMES = 1;
    public const int FORMAT_MILLISECONDS = 2;

    /**
     * Constructs the ETCO frame with given parameters.
     */
    public function __construct(
        protected array $events = [],
        protected int   $format = self::FORMAT_MILLISECONDS,
    )
    {
        parent::__construct('ETCO', Encoding::UTF8);
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
     * Adds an event with its timestamp.
     */
    public function addEvent(int $timestamp, int $eventType): self
    {
        $this->events[$timestamp] = $eventType;
        ksort($this->events);
        return $this;
    }

    /**
     * Parses the frame data.
     */
    public function parse(string $frameData): self
    {
        // The first byte is time format
        if (strlen($frameData) < 1) {
            return $this;
        }
        $this->format = ord($frameData[0]);

        // Parse the events
        $offset = 1;
        $this->events = [];

        while ($offset + 4 < strlen($frameData)) {
            // Read the event type (1 byte)
            $eventType = ord($frameData[$offset]);
            $offset++;

            // Read the timestamp (4 bytes)
            $timestamp = (
                ord($frameData[$offset]) << 24 |
                ord($frameData[$offset + 1]) << 16 |
                ord($frameData[$offset + 2]) << 8 |
                ord($frameData[$offset + 3])
            );
            $offset += 4;

            // Store the event
            $this->events[$timestamp] = $eventType;
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
        // Start with the format byte
        $data = chr($this->format);

        // Add each event (event type byte followed by timestamp)
        foreach ($this->events as $timestamp => $eventType) {
            // Add the event type (1 byte)
            $data .= chr($eventType);

            // Add the timestamp (4 bytes, big-endian)
            $data .= chr(($timestamp >> 24) & 0xFF);
            $data .= chr(($timestamp >> 16) & 0xFF);
            $data .= chr(($timestamp >> 8) & 0xFF);
            $data .= chr($timestamp & 0xFF);
        }

        return $data;
    }
}
