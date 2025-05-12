<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * EQUA frame - Equalization.
 *
 * The 'Equalization' frame allows the user to adjust the audio to their taste by specifying
 * frequency adjustments. It contains an adjustment bit and a list of frequency/adjustment pairs.
 */
class EQUA extends Frame
{
    /**
     * Constructs the EQUA frame with given parameters.
     */
    public function __construct(
        protected bool  $adjustmentBit = false,
        protected array $adjustments = [],
    )
    {
        parent::__construct('EQUA', Encoding::UTF8);
    }

    /**
     * Returns the adjustment bit.
     */
    public function getAdjustmentBit(): bool
    {
        return $this->adjustmentBit;
    }

    /**
     * Sets the adjustment bit.
     */
    public function setAdjustmentBit(bool $adjustmentBit): self
    {
        $this->adjustmentBit = $adjustmentBit;
        return $this;
    }

    /**
     * Returns the frequency adjustments.
     */
    public function getAdjustments(): array
    {
        return $this->adjustments;
    }

    /**
     * Sets the frequency adjustments.
     */
    public function setAdjustments(array $adjustments): self
    {
        $this->adjustments = $adjustments;
        return $this;
    }

    /**
     * Adds a frequency adjustment.
     */
    public function addAdjustment(int $frequency, int $adjustment): self
    {
        $this->adjustments[$frequency] = $adjustment;
        return $this;
    }

    /**
     * Parses the frame data.
     */
    public function parse(string $frameData): self
    {
        // The first byte is the adjustment bit
        if (strlen($frameData) < 1) {
            return $this;
        }
        $this->adjustmentBit = (bool)ord($frameData[0]);

        // Parse the frequency/adjustment pairs
        $offset = 1;
        $this->adjustments = [];

        while ($offset + 3 < strlen($frameData)) {
            // Read the frequency (2 bytes)
            $frequency = (
                ord($frameData[$offset]) << 8 |
                ord($frameData[$offset + 1])
            );
            $offset += 2;

            // Read the adjustment (1 byte)
            $adjustment = ord($frameData[$offset]);
            $offset++;

            // Store the adjustment
            $this->adjustments[$frequency] = $adjustment;
        }

        return $this;
    }

    /**
     * Converts the frame to binary data.
     */
    public function toBytes(): string
    {
        // Start with the adjustment bit byte
        $data = chr($this->adjustmentBit ? 1 : 0);

        // Add each frequency/adjustment pair
        foreach ($this->adjustments as $frequency => $adjustment) {
            // Add the frequency (2 bytes, big-endian)
            $data .= chr(($frequency >> 8) & 0xFF);
            $data .= chr($frequency & 0xFF);

            // Add the adjustment (1 byte)
            $data .= chr($adjustment);
        }

        return $data;
    }
}
