<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\MBID;

trait DiscIdTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the disc ID.
     *
     * @return string
     */
    public static function discId(): string
    {
        return 'discid';
    }

    /**
     * Adds the MusicBrainz Identifier (MBID) of the disc.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the disc
     *
     * @return Term
     */
    public function addDiscId(MBID $mbid): Term
    {
        return $this->addTerm((string) $mbid, self::discId());
    }
}
