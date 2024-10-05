<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Area;
use MusicBrainz\Value\ArtistType;

use function is_null;

/**
 * Provides a getter for the ending area.
 */
trait EndAreaTrait
{
    /**
     * The ending area indicates where an artist started its existence. Its exact meaning depends on the type of artist:
     *
     * - For a person: The person's place of death
     * - For a group (or orchestra/choir): The area when the group last dissolved
     * - For a character: The area (in real life) where the character concept was created
     * - For others: There are no clear indications about how to use dates for artists of the type "Other" at the moment.
     *
     * @var Area
     *
     * @see ArtistType
     */
    private Area $endArea;

    /**
     * Returns the artist's ending area.
     *
     * @return Area
     */
    public function getEndArea(): Area
    {
        return $this->endArea;
    }

    /**
     * Sets the artist's ending area by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setEndAreaFromArray(array $input): void
    {
        $this->endArea = is_null($endArea = ArrayAccess::getArray($input, 'end-area'))
            ? new Area()
            : new Area($endArea);
    }
}
