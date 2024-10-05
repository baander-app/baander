<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target;

use MusicBrainz\Relation;
use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\Release;

/**
 * A release relation
 */
class RelationToRelease extends Relation
{
    /**
     * The related release
     *
     * @var Release
     */
    private $release;

    /**
     * Sets the related release.
     *
     * @param array $release Information about the related release
     *
     * @return void
     */
    protected function setRelatedEntity(array $release): void
    {
        $this->release = new Release($release);
    }

    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::RELEASE);
    }

    /**
     * Returns the related release.
     *
     * @return Release
     */
    public function getRelease(): Release
    {
        return $this->release;
    }
}
