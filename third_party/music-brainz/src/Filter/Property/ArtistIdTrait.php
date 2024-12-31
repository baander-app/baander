<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\MBID;

trait ArtistIdTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the artist ID.
     *
     * @return string
     */
    public static function artistId(): string
    {
        return 'arid';
    }

    /**
     * Adds the MusicBrainz Identifier (MBID) of an artist.
     *
     * @param MBID $artistId The MusicBrainz Identifier (MBID) of an artist
     *
     * @return Term
     */
    public function addArtistId(MBID $artistId): Term
    {
        return $this->addTerm((string) $artistId, self::artistId());
    }
}
