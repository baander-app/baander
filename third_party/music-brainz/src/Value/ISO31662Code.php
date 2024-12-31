<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * An ISO 3166-2 code
 *
 * @link https://en.wikipedia.org/wiki/ISO_3166-2
 */
class ISO31662Code implements ISO3166Code
{
    /**
     * The ISO 3166-2 code
     *
     * @var string
     */
    private $code;

    /**
     * Constructs an ISO 3166-2 code.
     *
     * @param string $code An ISO 3166-2 code
     */
    public function __construct(string $code = '')
    {
        $this->code = $code;
    }

    /**
     * Returns the ISO 3166-2 code as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->code;
    }
}
