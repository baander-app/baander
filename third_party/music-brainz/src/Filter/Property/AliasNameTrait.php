<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait AliasNameTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the alias name.
     *
     * @return string
     */
    public static function aliasName(): string
    {
        return 'alias';
    }

    /**
     * Adds an alias.
     *
     * @param Name $aliasName An alias name
     *
     * @return Phrase
     */
    public function addAliasName(Name $aliasName): Phrase
    {
        return $this->addPhrase((string) $aliasName, self::aliasName());
    }
}
