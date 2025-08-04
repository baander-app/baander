<?php

namespace App\Modules\Metadata\MediaMeta\Frame;

use App\Modules\Metadata\MediaMeta\Encoding;

/**
 * POPM frame - Popularimeter.
 *
 * The 'Popularimeter' frame is meant to specify how good an audio file is.
 * It contains a user email, a rating, and a play counter.
 */
class POPM extends Frame
{
    /**
     * Constructs the POPM frame with given parameters.
     */
    public function __construct(
        protected string $email = '',
        protected int    $rating = 0,
        protected int    $counter = 0,
    )
    {
        parent::__construct('POPM');
    }

    /**
     * Returns the email.
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Sets the email.
     */
    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    /**
     * Returns the rating.
     */
    public function getRating(): int
    {
        return $this->rating;
    }

    /**
     * Sets the rating.
     */
    public function setRating(int $rating): self
    {
        $this->rating = $rating;
        return $this;
    }

    /**
     * Returns the counter.
     */
    public function getCounter(): int
    {
        return $this->counter;
    }

    /**
     * Sets the counter.
     */
    public function setCounter(int $counter): self
    {
        $this->counter = $counter;
        return $this;
    }

    /**
     * Parses the frame data.
     */
    public function parse(string $frameData): self
    {
        // Find the null terminator for the email
        $emailEnd = strpos($frameData, "\0");
        if ($emailEnd === false) {
            return $this;
        }

        // Extract the email
        $this->email = substr($frameData, 0, $emailEnd);

        // Extract the rating (1 byte)
        if ($emailEnd + 1 < strlen($frameData)) {
            $this->rating = ord($frameData[$emailEnd + 1]);
        }

        // Extract the counter (variable length)
        if ($emailEnd + 2 < strlen($frameData)) {
            $counterData = substr($frameData, $emailEnd + 2);
            $this->counter = 0;

            // Parse the counter as a big-endian integer
            for ($i = 0; $i < strlen($counterData); $i++) {
                $this->counter = ($this->counter << 8) | ord($counterData[$i]);
            }
        }

        return $this;
    }

    /**
     * Converts the frame to binary data.
     */
    public function toBytes(): string
    {
        // Start with the email and null terminator
        $data = $this->email . "\0";

        // Add the rating (1 byte)
        $data .= chr($this->rating);

        // Add the counter (variable length)
        $counter = $this->counter;
        $counterBytes = '';

        // Convert the counter to bytes (big-endian)
        while ($counter > 0) {
            $counterBytes = chr($counter & 0xFF) . $counterBytes;
            $counter >>= 8;
        }

        // If counter is 0, add a single byte
        if (empty($counterBytes)) {
            $counterBytes = "\0";
        }

        return $data . $counterBytes;
    }
}
