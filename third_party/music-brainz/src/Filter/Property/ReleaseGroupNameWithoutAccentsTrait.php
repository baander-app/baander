<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait ReleaseGroupNameWithoutAccentsTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the name of the release group (with accents).
     *
     * @return string
     */
    public static function releaseGroupNameWithoutAccent(): string
    {
        return 'releasegroup';
    }

    /**
     * Adds the name of the release group (without accents).
     *
     * @param Name $releaseGroupNameWithoutAccents The name of the release group (without accents)
     *
     * @return Phrase
     */
    public function addReleaseGroupNameWithoutAccents(Name $releaseGroupNameWithoutAccents): Phrase
    {
        return $this->addPhrase((string) $releaseGroupNameWithoutAccents, self::releaseGroupNameWithoutAccent());
    }
}
