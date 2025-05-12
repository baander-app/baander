<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * TPUB frame - Publisher.
 *
 * The 'Publisher' frame contains the name of the label or publisher.
 */
class TPUB extends TextFrame
{
    /**
     * Constructs the TPUB frame with given parameters.
     */
    public function __construct(string $publisher = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TPUB', $publisher, $encoding);
    }

    /**
     * Returns the publisher.
     */
    public function getPublisher(): string
    {
        return $this->getText();
    }

    /**
     * Sets the publisher.
     */
    public function setPublisher(string $publisher): self
    {
        return $this->setText($publisher);
    }
}