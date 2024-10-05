<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A MusicBrainz Identifier (MBID)
 *
 * @see https://musicbrainz.org/doc/MusicBrainz_Identifier
 */
class MBID implements Value
{
    /**
     * The MusicBrainz Identifier (MBID)
     *
     * @var string
     */
    private $mbid;

    /**
     * Constructs a MusicBrainz Identifier (MBID).
     *
     * @param null|string $mbid A MusicBrainz Identifier (MBID)
     */
    public function __construct(?string $mbid = '')
    {
        $this->mbid = isset($mbid) && ($this->isValidMBID($mbid))
            ? $mbid
            : '';
    }

    /**
     * Returns true, if the given string, is a valid MusicBrainz Identifier (MBID), otherwise false.
     *
     * @param string $mbid A string to be validated
     *
     * @return bool
     */
    private function isValidMBID(string $mbid): bool
    {
        return (bool) preg_match("/^(\{)?[a-f\d]{8}(-[a-f\d]{4}){4}[a-f\d]{8}(?(1)\})$/i", $mbid);
    }

    /**
     * Returns the MusikBrainz Identifier (MBID) as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->mbid;
    }
}
