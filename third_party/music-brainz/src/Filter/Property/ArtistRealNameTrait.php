<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait ArtistRealNameTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the “real name” of an artist.
     *
     * @return string
     */
    public static function artistRealName(): string
    {
        return 'artistname';
    }

    /**
     * Adds a “real name” of any artist that is included in the release group’s artist credit.
     *
     * @param Name $artistRealName A “real name” of any artist that is included in the release group’s artist credit
     *
     * @return Phrase
     */
    public function addArtistRealName(Name $artistRealName): Phrase
    {
        return $this->addPhrase((string) $artistRealName, self::artistRealName());
    }
}
