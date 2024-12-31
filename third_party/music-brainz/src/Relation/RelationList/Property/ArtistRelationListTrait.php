<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\RelationList\ArtistRelationList;

use function is_null;

/**
 * Provides a getter for the list of relations an artist stands in.
 */
trait ArtistRelationListTrait
{
    /**
     * A list of relations the artist stands in.
     *
     * @var ArtistRelationList
     */
    private ArtistRelationList $relations;

    /**
     * Returns the list of relations the artist stands in.
     *
     * @return ArtistRelationList
     */
    public function getRelations(): ArtistRelationList
    {
        return $this->relations;
    }

    /**
     * Sets the list of relations the artist stands in by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setRelationsFromArray(array $input): void
    {
        if (!is_null(ArrayAccess::getArray($input, 'relation-list'))) {
            if (!is_null($array = ArrayAccess::getArray($input['relation-list'], 0))) {
                if (!is_null($array = ArrayAccess::getArray($array, 'relations'))) {
                    $this->relations = new ArtistRelationList($array);

                    return;
                }
            }

            $this->relations = new ArtistRelationList();
        }

        $this->relations = ($array = ArrayAccess::getArray($input, 'relations'))
            ? new ArtistRelationList($array)
            : new ArtistRelationList();
    }
}
