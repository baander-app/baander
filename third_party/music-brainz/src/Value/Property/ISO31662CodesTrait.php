<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\ISO31662Code;
use MusicBrainz\Value\ISO31662CodeList;

use function is_null;

/**
 * Provides a getter for a list of ISO 3166-2 codes.
 */
trait ISO31662CodesTrait
{
    /**
     * A list of ISO 3166-2 codes associated to the artist
     *
     * @var ISO31662Code[]|ISO31662CodeList
     */
    private ISO31662CodeList $ISO31662Codes;

    /**
     * Returns a list of ISO 3166-2 codes.
     *
     * @return ISO31662Code[]|ISO31662CodeList
     */
    public function getISO31662Codes(): ISO31662CodeList
    {
        return $this->ISO31662Codes;
    }

    /**
     * Sets a list of ISO 3166-2 by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setIso31662CodesFromArray(array $input): void
    {
        $this->ISO31662Codes = is_null($ISO31662Codes = ArrayAccess::getArray($input, 'iso-3166-2-codes'))
            ? new ISO31662CodeList()
            : new ISO31662CodeList($ISO31662Codes);
    }
}
