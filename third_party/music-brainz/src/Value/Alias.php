<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * Aliases are variant names that are mostly used as search help: if a search matches an entity's alias, the entity will
 * be given as a result - even if the actual name wouldn't be.
 *
 * @see https://musicbrainz.org/doc/Aliases
 */
class Alias implements Value
{
    use Property\AliasTypeTrait;
    use Property\BeginDateTrait;
    use Property\EndDateTrait;
    use Property\EndedTrait;
    use Property\LocaleCodeTrait;
    use Property\NameTrait;
    use Property\PrimaryNameFlagTrait;
    use Property\SortNameTrait;

    /**
     * Constructs an alias.
     *
     * @param array $alias An array with information about the alias
     */
    public function __construct(array $alias = [])
    {
        $this->setAliasTypeFromArray($alias);
        $this->setBeginDateFromArray($alias);
        $this->setEndDateFromArray($alias);
        $this->setEndedFromArray($alias);
        $this->setLocaleCodeFromArray($alias);
        $this->setNameFromArray($alias);
        $this->setPrimaryNameFromArray($alias);
        $this->setSortNameFromArray($alias);
    }

    /**
     * Returns the alternative name.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getName();
    }
}
