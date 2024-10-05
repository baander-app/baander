<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target;

use MusicBrainz\Relation;
use MusicBrainz\Value\Artist;
use MusicBrainz\Value\EntityType;

/**
 * An artist relation
 */
class RelationToArtist extends Relation
{
    /**
     * The related artist
     *
     * @var Artist
     */
    private $artist;

    /**
     * Sets the related artist.
     *
     * @param array $artist Information about the related artist
     *
     * @return void
     */
    protected function setRelatedEntity(array $artist): void
    {
        $this->artist = new Artist($artist);
    }

    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::ARTIST);
    }

    /**
     * Returns the related artist.
     *
     * @return Artist
     */
    public function getArtist(): Artist
    {
        return $this->artist;
    }
}
