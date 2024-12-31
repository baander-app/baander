<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait ArtistNameWithoutAccentsTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the artist's name (without accented characters).
     *
     * @return string
     */
    public static function artistNameWithoutAccent(): string
    {
        return 'artist';
    }

    /**
     * Adds the artist's name (without accented characters).
     *
     * @param Name $artistNameWithoutAccents The name of the artist's name (without accented characters)
     *
     * @return Phrase
     */
    public function addArtistNameWithoutAccents(Name $artistNameWithoutAccents): Phrase
    {
        return $this->addPhrase((string) $artistNameWithoutAccents, self::artistNameWithoutAccent());
    }
}
