<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Relation\RelationList\Property\PlaceRelationListTrait;
use MusicBrainz\Value;

/**
 * A place
 *
 * @link https://musicbrainz.org/doc/Place
 */
class Place implements Value
{
    use PlaceRelationListTrait;
    use Property\AddressTrait;
    use Property\AreaTrait;
    use Property\CoordinatesTrait;
    use Property\DisambiguationTrait;
    use Property\LifeSpanTrait;
    use Property\MBIDTrait;
    use Property\NameTrait;
    use Property\PlaceTypeTrait;

    /**
     * Constructs a place.
     *
     * @param array $place Information about the place
     */
    public function __construct(array $place = [])
    {
        $this->setRelationsFromArray($place);
        $this->setAddressFromArray($place);
        $this->setAreaFromArray($place);
        $this->setCoordinatesFromArray($place);
        $this->setDisambiguationFromArray($place);
        $this->setLifeSpanFromArray($place);
        $this->setMbidFromArray($place);
        $this->setNameFromArray($place);
        $this->setPlaceTypeFromArray($place);
    }

    /**
     * Returns the place as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getName();
    }
}
