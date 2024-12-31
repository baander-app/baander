<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\ISWC;
use MusicBrainz\Value\ISWCList;

use function is_null;

/**
 * Provides a getter for a list of International Standard Musical Work Codes (ISWC).
 */
trait IswcsTrait
{
    /**
     * A list of International Standard Musical Work Codes (ISWC)
     *
     * @var ISWC[]|ISWCList
     */
    private ISWCList $iswcs;

    /**
     * Returns a list of International Standard Musical Work Codes (ISWC).
     *
     * @return ISWC[]|ISWCList
     */
    public function getIswcs(): ISWCList
    {
        return $this->iswcs;
    }

    /**
     * Sets the list of International Standard Musical Work Codes (ISWC) by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setIswcsFromArray(array $input): void
    {
        $this->iswcs = is_null($iswcs = ArrayAccess::getArray($input, 'iswcs'))
            ? new ISWCList()
            : new ISWCList($iswcs);
    }
}
