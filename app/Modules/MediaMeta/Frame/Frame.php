<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * Base class for ID3v2 frames.
 *
 * This class provides common functionality for all frame types.
 */
abstract class Frame implements Encoding
{
    /**
     * Constructs the Frame class with given parameters.
     */
    public function __construct(
        protected string $frameId {
            get {
                return $this->frameId;
            }
        },
        protected int    $encoding = Encoding::UTF8,
    )
    {
    }

    /**
     * Returns the text encoding.
     */
    public function getEncoding(): int
    {
        return $this->encoding;
    }

    /**
     * Sets the text encoding.
     */
    public function setEncoding(int $encoding): self
    {
        $this->encoding = $encoding;
        return $this;
    }

    /**
     * Parses the frame data.
     */
    abstract public function parse(string $frameData): self;

    /**
     * Converts the frame to binary data.
     */
    abstract public function toBytes(): string;
}
