<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Instrument;

use function is_null;

/**
 * Provides a getter for the instrument.
 */
trait InstrumentTrait
{
    /**
     * The instrument
     *
     * @var Instrument
     */
    private Instrument $instrument;

    /**
     * Returns the instrument.
     *
     * @return Instrument
     */
    public function getInstrument(): Instrument
    {
        return $this->instrument;
    }

    /**
     * Sets the instrument by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setInstrumentFromArray(array $input): void
    {
        $this->instrument = is_null($sortName = ArrayAccess::getString($input, 'instrument'))
            ? new Instrument()
            : new Instrument($sortName);
    }
}
