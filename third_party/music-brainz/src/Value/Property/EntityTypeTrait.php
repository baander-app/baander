<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\EntityType;

use function is_null;

/**
 * Provides a getter for the entity type.
 */
trait EntityTypeTrait
{
    /**
     * The type is used to state whether an entity is a person, a group, or something else.
     *
     * @var EntityType
     */
    private EntityType $entityType;

    /**
     * Returns the entity type.
     *
     * @return EntityType
     */
    public function getEntityType(): EntityType
    {
        return $this->entityType;
    }

    /**
     * Sets the entity type by extracting it from a given input array.
     *
     * @param array  $input An array returned by the webservice
     * @param string $key   An array key. Default: 'entity-type'
     *
     * @return void
     */
    private function setEntityTypeFromArray(array $input, string $key = 'entity-type'): void
    {
        $this->entityType = is_null($entityType = ArrayAccess::getString($input, $key))
            ? new EntityType()
            : new EntityType($entityType);
    }
}
