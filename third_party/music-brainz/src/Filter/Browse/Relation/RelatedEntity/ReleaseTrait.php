<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for the entity type release.
 */
trait ReleaseTrait
{
    /**
     * Defines a relation to a release.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related release
     *
     * @return void
     */
    public function setEntityTypeRelease(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::RELEASE));
    }
}
