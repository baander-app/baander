<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target;

use MusicBrainz\Relation;
use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\Place;

/**
 * A place relation
 */
class RelationToPlace extends Relation
{
    /**
     * The related place
     *
     * @var Place
     */
    private $place;

    /**
     * Sets the related place.
     *
     * @param array $place Information about the related place
     *
     * @return void
     */
    protected function setRelatedEntity(array $place): void
    {
        $this->place = new Place($place);
    }

    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::PLACE);
    }

    /**
     * Returns the related place.
     *
     * @return Place
     */
    public function getPlace(): Place
    {
        return $this->place;
    }
}
