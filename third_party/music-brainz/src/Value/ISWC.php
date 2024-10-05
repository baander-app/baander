<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * An International Standard Musical Work Code (ISWC)
 * The International Standard Musical Work Code (ISWC) is an ISO standard similar to ISBNs for identifying musical
 * works/compositions.
 *
 * @link https://musicbrainz.org/doc/ISWC
 */
class ISWC implements Value
{
    /**
     * The code
     *
     * @var string
     */
    private $code;

    /**
     * Constructs an ISWC.
     *
     * @param string $code An ISWC
     */
    public function __construct(string $code = '')
    {
        $this->code = $code;
    }

    /**
     * Returns the ISWC as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->code;
    }
}
