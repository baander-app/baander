<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Ended;

use function is_null;

/**
 * Provides a getter for the "ended" flag.
 */
trait EndedTrait
{
    /**
     * An "ended" flag
     *
     * @var Ended
     */
    private Ended $ended;

    /**
     * Returns the "ended" flag
     *
     * @return Ended
     */
    public function getEnded(): Ended
    {
        return $this->ended;
    }

    /**
     * Sets the "ended" flag by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setEndedFromArray(array $input): void
    {
        $this->ended = is_null($ended = ArrayAccess::getBool($input, 'ended'))
            ? new Ended()
            : new Ended($ended);
    }
}
