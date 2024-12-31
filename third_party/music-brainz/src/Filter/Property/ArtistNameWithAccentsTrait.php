<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait ArtistNameWithAccentsTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the artist's name (with accented characters).
     *
     * @return string
     */
    public static function artistNameWithAccent(): string
    {
        return 'artistaccent';
    }

    /**
     * Adds the artist's name (with accented characters).
     *
     * @param Name $artistNameWithAccents The name of the artist's name (with accented characters)
     *
     * @return Phrase
     */
    public function addArtistNameWithAccents(Name $artistNameWithAccents): Phrase
    {
        return $this->addPhrase((string) $artistNameWithAccents, self::artistNameWithAccent());
    }
}
