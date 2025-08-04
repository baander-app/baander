<?php

namespace App\Modules\Metadata\MediaMeta\Frame;

use App\Modules\Metadata\MediaMeta\Encoding;

/**
 * LINK frame - Linked information.
 *
 * The 'Linked information' frame is used to link information from another ID3v2 tag that might
 * be present in another audio file or alone in a binary file. It consists of a frame identifier,
 * URL, and additional data.
 */
class LINK extends Frame
{
    /**
     * Constructs the LINK frame with given parameters.
     */
    public function __construct(
        protected string $frameIdentifier = '',
        protected string $url = '',
        protected string $additionalData = '',
    )
    {
        parent::__construct('LINK');
    }

    /**
     * Returns the frame identifier.
     */
    public function getFrameIdentifier(): string
    {
        return $this->frameIdentifier;
    }

    /**
     * Sets the frame identifier.
     */
    public function setFrameIdentifier(string $frameIdentifier): self
    {
        $this->frameIdentifier = substr($frameIdentifier, 0, 4);
        return $this;
    }

    /**
     * Returns the URL.
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Sets the URL.
     */
    public function setUrl(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * Returns the additional data.
     */
    public function getAdditionalData(): string
    {
        return $this->additionalData;
    }

    /**
     * Sets the additional data.
     */
    public function setAdditionalData(string $additionalData): self
    {
        $this->additionalData = $additionalData;
        return $this;
    }

    /**
     * Parses the frame data.
     */
    public function parse(string $frameData): self
    {
        // The first 4 bytes are the frame identifier
        if (strlen($frameData) < 4) {
            return $this;
        }
        $this->frameIdentifier = substr($frameData, 0, 4);

        // Find the null terminator for the URL
        $urlStart = 4;
        $urlEnd = strpos($frameData, "\0", $urlStart);
        if ($urlEnd === false) {
            $this->url = substr($frameData, $urlStart);
            return $this;
        }

        // Extract the URL
        $this->url = substr($frameData, $urlStart, $urlEnd - $urlStart);

        // Extract the additional data
        if ($urlEnd + 1 < strlen($frameData)) {
            $this->additionalData = substr($frameData, $urlEnd + 1);
        }

        return $this;
    }

    /**
     * Converts the frame to binary data.
     */
    public function toBytes(): string
    {
        // Start with the frame identifier (4 bytes)
        $data = $this->frameIdentifier;

        // Add the URL with null terminator
        $data .= $this->url . "\0";

        // Add the additional data
        $data .= $this->additionalData;

        return $data;
    }
}
