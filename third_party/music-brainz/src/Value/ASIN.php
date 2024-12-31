<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * An Amazon Standard Identification Number (ASIN)
 *
 * @link https://musicbrainz.org/doc/ASIN
 */
class ASIN implements Value
{
    /**
     * The Amazon Standard Identification Number (ASIN) as string
     *
     * @var string
     */
    private $code;

    /**
     * Constructs an Amazon Standard Identification Number (ASIN).
     *
     * @param string $code The Amazon Standard Identification Number (ASIN) as string
     */
    public function __construct(string $code = '')
    {
        $this->code = $code;
    }

    /**
     * Returns the Amazon Standard Identification Number (ASIN) as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->code;
    }
}
