<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * International Standard Name Identifier
 * The International Standard Name Identifier (ISNI) is an ISO standard for uniquely identifying the public identities
 * of contributors to media content.
 * ISNI will provide a tool for disambiguating names that might otherwise be confused, and will link the data about
 * names that is collected and used in all sectors of the media industries.
 * MusicBrainz stores ISNIs for artists and labels.
 *
 * @link https://musicbrainz.org/doc/ISNI
 * @link http://www.isni.org/
 * @link https://en.wikipedia.org/wiki/International_Standard_Name_Identifier
 */
class ISNI implements Value
{
    /**
     * The ISNI code
     *
     * @var string
     */
    private $code;

    /**
     * Constructs an ISNI code.
     *
     * @param string $code An ISNI code
     */
    public function __construct(string $code)
    {
        $this->code = $code;
    }

    /**
     * Returns the ISNI code.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->code;
    }
}
