<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\Target\RelationList\RelationToPlaceList;
use MusicBrainz\Relation\Target\RelationToPlace;
use MusicBrainz\Value\EntityType;

use function is_null;

/**
 * Provides a getter for the list of relations to a place.
 */
trait RelationsToPlaceTrait
{
    /**
     * A list of relations to a place
     *
     * @var RelationToPlace[]|RelationToPlaceList
     */
    private RelationToPlaceList $placeRelations;

    /**
     * Returns a list of relations to a place.
     *
     * @return RelationToPlace[]|RelationToPlaceList
     */
    public function getPlaceRelations(): RelationToPlaceList
    {
        return $this->placeRelations;
    }

    /**
     * Sets a list of relations to a place by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setPlaceRelationsFromArray(array $input): void
    {
        $this->placeRelations = is_null($input = ArrayAccess::getArray($input, EntityType::PLACE))
            ? new RelationToPlaceList()
            : new RelationToPlaceList($input);
    }
}
