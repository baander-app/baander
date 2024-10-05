<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\DiscList;

use function is_null;

/**
 * Provides a getter for a list of discs.
 */
trait DiscsTrait
{
    /**
     * A list of discs
     *
     * @var DiscList
     */
    private DiscList $discs;

    /**
     * Returns a list of discs.
     *
     * @return DiscList
     */
    public function getDiscs(): DiscList
    {
        return $this->discs;
    }

    /**
     * Sets the list of discs by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setDiscsFromArray(array $input): void
    {
        $this->discs = is_null($discs = ArrayAccess::getArray($input, 'discs'))
            ? new DiscList()
            : new DiscList($discs);
    }
}
