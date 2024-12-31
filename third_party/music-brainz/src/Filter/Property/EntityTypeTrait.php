<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\EntityType;

trait EntityTypeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the type of entity.
     *
     * @return string
     */
    public static function entityType(): string
    {
        return 'type';
    }

    /**
     * Adds the type of entity.
     *
     * @param EntityType $entityType The type of entity
     *
     * @return Term
     */
    public function addEntityType(EntityType $entityType): Term
    {
        return $this->addTerm((string) $entityType, self::entityType());
    }
}
