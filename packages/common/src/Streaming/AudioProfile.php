<?php

namespace Baander\Common\Streaming;

class AudioProfile
{
    public function __construct(
        public int $bitrate,                    // Audio bitrate in bps (e.g., 128000)
        public ?int $channels = null,           // Number of audio channels (e.g., 1 for mono, 2 for stereo)
        public ?int $sampleRate = null,         // Sample rate in Hz (e.g., 44100, 48000)
        public ?string $codec = null,           // Codec type (e.g., "AAC", "MP3", "Opus")
    ) {
    }

    /**
     * Get a description of the audio profile.
     *
     * @return string
     */
    public function __toString(): string
    {
        $description = "{$this->bitrate}bps";
        if ($this->channels) {
            $description .= ", {$this->channels} channels";
        }
        if ($this->sampleRate) {
            $description .= ", {$this->sampleRate}Hz";
        }
        if ($this->codec) {
            $description .= ", {$this->codec} codec";
        }
        if ($this->bitDepth) {
            $description .= ", {$this->bitDepth}-bit depth";
        }

        return $description;
    }

    /**
     * Compare two AudioProfiles to see if they are equivalent.
     *
     * @param AudioProfile $other The other AudioProfile to compare.
     * @return bool True if profiles are equivalent, false otherwise.
     */
    public function equals(AudioProfile $other): bool
    {
        return $this->bitrate === $other->bitrate &&
            $this->channels === $other->channels &&
            $this->sampleRate === $other->sampleRate &&
            $this->codec === $other->codec;
    }

    /**
     * Estimate the total audio size based on duration and bitrate.
     *
     * @param float $duration Duration of audio in seconds.
     * @return float Estimated file size in bytes.
     */
    public function estimateSize(float $duration): float
    {
        return ($this->bitrate / 8) * $duration; // Bitrate in bytes per second * duration
    }
}