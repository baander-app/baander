<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for the entity type release group.
 */
trait ReleaseGroupTrait
{
    /**
     * SDefines a relation to a release group.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related entity
     *
     * @return void
     */
    public function setEntityTypeReleaseGroup(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::RELEASE_GROUP));
    }
}
