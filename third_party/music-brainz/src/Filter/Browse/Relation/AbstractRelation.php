<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * An abstract relation to an entity
 * Browse requests are a direct lookup of all the entities directly linked to another entity. For performing them,
 * relation objects may be used to specify the linked entities.
 * As the related entity is defined by its MusicBrainz Identifier (MBID) and its type, this class provides getters and
 * setters for both. The setter for the type of the related entity is protected, because an entity of a certain type
 * may only be related to entities of certain types. For this reason there are derived relation classes for each entity
 * type providing public setters for the possible types of related entities.
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2#Browse
 */
abstract class AbstractRelation
{
    /**
     * The MusicBrainz Identifier (MBID) for the related entity
     *
     * @var MBID
     */
    private $entityId;

    /**
     * The type of the related entity
     *
     * @var EntityType
     */
    private $entityType;

    /**
     * Returns the MusicBrainz Identifier (MBID) of the related entity.
     *
     * @return MBID
     */
    public function getEntityId(): MBID
    {
        return $this->entityId;
    }

    /**
     * Sets the MusicBrainz Identifier (MBID) of the related entity.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related entity
     *
     * @return self
     */
    protected function setEntityId(MBID $mbid): self
    {
        $this->entityId = $mbid;

        return $this;
    }

    /**
     * Returns the type of the related entity.
     *
     * @return EntityType
     */
    public function getEntityType(): EntityType
    {
        return $this->entityType;
    }

    /**
     * Sets the type of the related entity.
     *
     * @param EntityType $entityType The type of the related entity
     */
    protected function setEntityType(EntityType $entityType): void
    {
        $this->entityType = $entityType;
    }
}
