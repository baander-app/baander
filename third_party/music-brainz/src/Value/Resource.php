<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A resource
 */
class Resource implements Value
{
    /**
     * The resource
     *
     * @var string
     */
    private $resource;

    /**
     * Constructs a resource.
     *
     * @param string $resource The resource
     */
    public function __construct(string $resource = '')
    {
        $this->resource = $resource;
    }

    /**
     * Returns the resource.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->resource;
    }
}
