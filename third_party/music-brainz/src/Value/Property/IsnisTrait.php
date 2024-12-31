<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\ISNIList;

use function is_null;

/**
 * Provides a getter for a list of IPI codes.
 */
trait IsnisTrait
{
    /**
     * A list of ISNI codes
     *
     * @var ISNIList
     */
    private ISNIList $isnis;

    /**
     * Returns a list of ISNI codes.
     *
     * @return ISNIList
     */
    public function getIsnis(): ISNIList
    {
        return $this->isnis;
    }

    /**
     * Sets a list of ISNI codes by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setIsnisFromArray(array $input): void
    {
        $this->isnis = is_null($isnis = ArrayAccess::getArray($input, 'isnis'))
            ? new ISNIList()
            : new ISNIList($isnis);
    }
}
