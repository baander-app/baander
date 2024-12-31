<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\ArtistType;

trait ArtistTypeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the type of artist.
     *
     * @return string
     */
    public static function artistType(): string
    {
        return 'type';
    }

    /**
     * Adds the type of artist.
     *
     * @param ArtistType $artistType The type of artist
     *
     * @return Term
     */
    public function addArtistType(ArtistType $artistType): Term
    {
        return $this->addTerm((string) $artistType, self::artistType());
    }
}
