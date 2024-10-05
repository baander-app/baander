<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\MBID;

trait EntityIdTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the entity ID.
     *
     * @return string
     */
    public static function entityId(): string
    {
        return 'entity';
    }

    /**
     * Adds the MusicBrainz Identifier (MBID) of an entity.
     *
     * @param MBID $entityId The MusicBrainz Identifier (MBID) of an entity
     *
     * @return Term
     */
    public function addEntityId(MBID $entityId): Term
    {
        return $this->addTerm((string) $entityId, self::entityId());
    }
}
