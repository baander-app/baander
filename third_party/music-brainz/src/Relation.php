<?php

declare(strict_types=1);

namespace MusicBrainz;

use MusicBrainz\Relation\Type;
use MusicBrainz\Value\Direction;
use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\Property\DirectionTrait;
use MusicBrainz\Value\Property\RelationTypeTrait;

/**
 * A relation
 */
abstract class Relation
{
    use DirectionTrait;
    use RelationTypeTrait;

    /**
     * Constructs the relation.
     *
     * @param array $entity Information about the related entity
     */
    final public function __construct(array $entity, Type $relationType, Direction $direction)
    {
        $this->setRelationType($relationType);
        $this->setDirection($direction);
        $this->setRelatedEntity($entity);
    }

    /**
     * Sets the related entity.
     *
     * @param mixed $entity Information about the related entity
     *
     * @return void
     */
    abstract protected function setRelatedEntity(mixed $entity): void;

    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    abstract public static function getRelatedEntityType(): EntityType;
}
