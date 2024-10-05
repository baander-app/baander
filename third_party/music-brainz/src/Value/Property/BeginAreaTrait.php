<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Area;
use MusicBrainz\Value\ArtistType;

use function is_null;

/**
 * Provides a getter for the beginning area.
 */
trait BeginAreaTrait
{
    /**
     * The beginning area indicates where an artist started its existence. Its exact meaning depends on the type of
     * artist:
     *
     * - For a person: The person's birthplace
     * - For a group (or orchestra/choir): The area where the group first formed
     *
     * @var Area
     *
     * @see ArtistType
     */
    private Area $beginArea;

    /**
     * Returns the artist's begin area.
     *
     * @return Area
     */
    public function getBeginArea(): Area
    {
        return $this->beginArea;
    }

    /**
     * Sets the artist's beginning area by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setBeginAreaFromArray(array $input): void
    {
        $this->beginArea = is_null($beginArea = ArrayAccess::getArray($input, 'begin_area'))
            ? new Area()
            : new Area($beginArea);
    }
}
