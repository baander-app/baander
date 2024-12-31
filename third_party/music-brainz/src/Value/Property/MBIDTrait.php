<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\MBID;

use function is_null;

/**
 * Provides a getter for the MusicBrainz Identifier (MBID).
 */
trait MBIDTrait
{
    /**
     * The MusicBrainz Identifier (MBID)
     *
     * @var MBID
     *
     * @see https://musicbrainz.org/doc/MusicBrainz_Identifier
     */
    private MBID $MBID;

    /**
     * Returns the MusicBrainz Identifier (MBID).
     *
     * @return MBID
     */
    public function getMBID(): MBID
    {
        return $this->MBID;
    }

    /**
     * Sets the MusicBrainz Identifier (MBID) by extracting it from a given input array.
     *
     * @param array  $input An array returned by the webservice
     * @param string $key   Optional array key. Default: 'id'
     *
     * @return void
     */
    private function setMbidFromArray(array $input, string $key = 'id'): void
    {
        $this->MBID = is_null($mbid = ArrayAccess::getString($input, $key))
            ? new MBID()
            : new MBID($mbid);
    }
}
