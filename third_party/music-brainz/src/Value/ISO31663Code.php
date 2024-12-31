<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * An ISO 3166-3 code
 *
 * @link https://en.wikipedia.org/wiki/ISO_3166-3
 */
class ISO31663Code implements ISO3166Code
{
    /**
     * The ISO 3166-3 code
     *
     * @var string
     */
    private $code;

    /**
     * Constructs an ISO 3166-3 code.
     *
     * @param string $code An ISO 3166-3 code
     */
    public function __construct(string $code = '')
    {
        $this->code = $code;
    }

    /**
     * Returns the ISO 3166-3 code as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->code;
    }
}
