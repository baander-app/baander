<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait ReleaseNameWithAccentsTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the release name (with accents).
     *
     * @return string
     */
    public static function releaseNameWithAccent(): string
    {
        return 'releaseaccent';
    }

    /**
     * Adds the name of a release (with accents).
     *
     * @param Name $releaseNameWithAccent The name of a release (with accents)
     *
     * @return Phrase
     */
    public function addReleaseNameWithAccent(Name $releaseNameWithAccent): Phrase
    {
        return $this->addPhrase((string) $releaseNameWithAccent, self::releaseNameWithAccent());
    }
}
