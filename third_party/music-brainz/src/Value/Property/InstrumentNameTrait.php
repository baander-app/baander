<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\InstrumentName;

use function is_null;

/**
 * Provides a getter for an instrument name.
 */
trait InstrumentNameTrait
{
    /**
     * The instrument name
     *
     * @var InstrumentName
     */
    public InstrumentName $instrumentName;

    /**
     * Returns the instrument name.
     *
     * @return InstrumentName
     */
    public function getInstrumentName(): InstrumentName
    {
        return $this->instrumentName;
    }

    /**
     * Sets the instrument name by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setInstrumentNameFromArray(array $input): void
    {
        $this->instrumentName = is_null($instrumentName = ArrayAccess::getString($input, 'name'))
            ? new InstrumentName()
            : new InstrumentName($instrumentName);
    }
}
