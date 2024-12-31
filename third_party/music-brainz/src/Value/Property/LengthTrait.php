<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Length;

use function is_null;

/**
 * Provides a getter for the length.
 */
trait LengthTrait
{
    /**
     * A length
     *
     * @var Length
     */
    private Length $length;

    /**
     * Returns the length.
     *
     * @return Length
     */
    public function getLength(): Length
    {
        return $this->length;
    }

    /**
     * Sets the length by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setLengthFromArray(array $input): void
    {
        $this->length = is_null($length = ArrayAccess::getInteger($input, 'length'))
            ? new Length()
            : new Length($length);
    }
}
