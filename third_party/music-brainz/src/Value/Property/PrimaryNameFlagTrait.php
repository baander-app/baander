<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\PrimaryNameFlag;

use function is_null;

/**
 * Provides a getter for the "primary name" flag.
 */
trait PrimaryNameFlagTrait
{
    /**
     * A "primary name" flag
     *
     * @var PrimaryNameFlag
     */
    private PrimaryNameFlag $primaryName;

    /**
     * Returns the "primary name" flag
     *
     * @return PrimaryNameFlag
     */
    public function getPrimaryName(): PrimaryNameFlag
    {
        return $this->primaryName;
    }

    /**
     * Sets the primary name by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setPrimaryNameFromArray(array $input): void
    {
        $this->primaryName = is_null($primaryName = ArrayAccess::getBool($input, 'primary'))
            ? new PrimaryNameFlag()
            : new PrimaryNameFlag($primaryName);
    }
}
