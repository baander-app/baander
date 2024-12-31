<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for the entity type editor.
 */
trait EditorTrait
{
    /**
     * Defines a relation to an editor.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related editor
     *
     * @return void
     */
    public function editor(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::EDITOR));
    }
}
