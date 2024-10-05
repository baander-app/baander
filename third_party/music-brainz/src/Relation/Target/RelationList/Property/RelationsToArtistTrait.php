<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\Target\RelationList\RelationToArtistList;
use MusicBrainz\Relation\Target\RelationToArtist;
use MusicBrainz\Value\EntityType;

use function is_null;

/**
 * Provides a getter for the list of relations to an artist.
 */
trait RelationsToArtistTrait
{
    /**
     * A list of relations to an artist
     *
     * @var RelationToArtist[]|RelationToArtistList
     */
    private RelationToArtistList $artistRelations;

    /**
     * Returns a list of relations to an artist.
     *
     * @return RelationToArtist[]|RelationToArtistList
     */
    public function getArtistRelations(): RelationToArtistList
    {
        return $this->artistRelations;
    }

    /**
     * Sets a list of relations to an artist by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setArtistRelationsFromArray(array $input): void
    {
        $this->artistRelations = is_null($input = ArrayAccess::getArray($input, EntityType::ARTIST))
            ? new RelationToArtistList()
            : new RelationToArtistList($input);
    }
}
