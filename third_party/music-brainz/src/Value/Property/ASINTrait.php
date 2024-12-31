<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\ASIN;

use function is_null;

/**
 * Provides a getter for the Amazon Standard Identification Number (ASIN).
 */
trait ASINTrait
{
    /**
     * The Amazon Standard Identification Number (ASIN)
     *
     * @var ASIN
     */
    private ASIN $asin;

    /**
     * Returns the Amazon Standard Identification Number (ASIN).
     *
     * @return ASIN
     */
    public function getAsin(): ASIN
    {
        return $this->asin;
    }

    /**
     * Sets the asin by extracting it from a given input array.
     *
     * @param array  $input An array returned by the webservice
     *
     * @return void
     */
    private function setAsinFromArray(array $input): void
    {
        $this->asin = is_null($asin = ArrayAccess::getString($input, 'asin'))
            ? new ASIN()
            : new ASIN($asin);
    }
}
