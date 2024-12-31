<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait EntityNameTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the entity name.
     *
     * @return string
     */
    public static function entityName(): string
    {
        return 'name';
    }

    /**
     * Adds an entity name.
     *
     * @param Name $entityName An entity name
     *
     * @return Phrase
     */
    public function addEntityName(Name $entityName): Phrase
    {
        return $this->addPhrase((string) $entityName, self::entityName());
    }
}
