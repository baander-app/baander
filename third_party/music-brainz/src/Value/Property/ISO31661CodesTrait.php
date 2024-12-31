<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\ISO31661Code;
use MusicBrainz\Value\ISO31661CodeList;

use function is_null;

/**
 * Provides a getter for a list of ISO 3166-1 codes.
 */
trait ISO31661CodesTrait
{
    /**
     * A list of ISO 3166-1 codes associated to the artist
     *
     * @var ISO31661Code[]|ISO31661CodeList
     */
    private ISO31661CodeList $ISO31661Codes;

    /**
     * Returns a list of ISO 3166-1 codes.
     *
     * @return ISO31661Code[]|ISO31661CodeList
     */
    public function getISO31661Codes(): ISO31661CodeList
    {
        return $this->ISO31661Codes;
    }

    /**
     * Sets a list of ISO 3166-1 by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setIso31661CodesFromArray(array $input): void
    {
        $this->ISO31661Codes = is_null($ISO31661Codes = ArrayAccess::getArray($input, 'iso-3166-1-codes'))
            ? new ISO31661CodeList()
            : new ISO31661CodeList($ISO31661Codes);
    }
}
