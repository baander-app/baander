<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\InstrumentType;
use MusicBrainz\Value\MBID;

/**
 * Provides a getter for the instrument type.
 */
trait InstrumentTypeTrait
{
    /**
     * The instrument type
     *
     * @var InstrumentType
     */
    private InstrumentType $instrumentType;

    /**
     * Returns the instrument type.
     *
     * @return InstrumentType
     */
    public function getInstrumentType(): InstrumentType
    {
        return $this->instrumentType;
    }

    /**
     * Sets the instrument type by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setInstrumentTypeFromArray(array $input): void
    {
        $this->instrumentType = new InstrumentType(
            ArrayAccess::getString($input, 'type'),
            new MBID(ArrayAccess::getString($input, 'type-id'))
        );
    }
}
