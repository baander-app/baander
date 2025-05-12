<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * MCDI frame - Music CD identifier.
 *
 * The 'Music CD identifier' frame contains the binary data from the Table of Contents (TOC)
 * of the CD, which is used as a unique identifier for the CD.
 */
class MCDI extends Frame
{
    /**
     * Constructs the MCDI frame with given parameters.
     */
    public function __construct(
        protected string $cdToc = '',
    )
    {
        parent::__construct('MCDI', Encoding::UTF8);
    }

    /**
     * Returns the CD TOC data.
     */
    public function getCdToc(): string
    {
        return $this->cdToc;
    }

    /**
     * Sets the CD TOC data.
     */
    public function setCdToc(string $cdToc): self
    {
        $this->cdToc = $cdToc;
        return $this;
    }

    /**
     * Parses the frame data.
     */
    public function parse(string $frameData): self
    {
        // The entire frame data is the CD TOC
        $this->cdToc = $frameData;
        return $this;
    }

    /**
     * Converts the frame to binary data.
     */
    public function toBytes(): string
    {
        // The entire frame data is the CD TOC
        return $this->cdToc;
    }
}
