<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait ReleaseGroupNameWithAccentsTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the name of the release group (with accents).
     *
     * @return string
     */
    public static function releaseGroupNameWithAccent(): string
    {
        return 'releasegroupaccent';
    }

    /**
     * Adds the name of the release group (with accents).
     *
     * @param Name $releaseGroupNameWithAccents The name of the release group (with accents)
     *
     * @return Phrase
     */
    public function addReleaseGroupNameWithAccents(Name $releaseGroupNameWithAccents): Phrase
    {
        return $this->addPhrase((string) $releaseGroupNameWithAccents, self::releaseGroupNameWithAccent());
    }
}
