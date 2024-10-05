<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\ReleaseType;

trait SecondaryTypeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the secondary release type.
     *
     * @return string
     */
    public static function secondaryType(): string
    {
        return 'secondarytype';
    }

    /**
     * Adds the secondary release type of the release group.
     *
     * @param ReleaseType $secondaryType The secondary release type of the release group
     *
     * @return Term
     */
    public function addSecondaryType(ReleaseType $secondaryType): Term
    {
        return $this->addTerm((string) $secondaryType, self::secondaryType());
    }
}
