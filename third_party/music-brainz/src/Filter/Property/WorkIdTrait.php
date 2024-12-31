<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\MBID;

trait WorkIdTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the work ID.
     *
     * @return string
     */
    public static function workId(): string
    {
        return 'wid';
    }

    /**
     * Adds the MusicBrainz Identifier (MBID) of a work.
     *
     * @param MBID $workId The MusicBrainz Identifier (MBID) of a work
     *
     * @return Term
     */
    public function addWorkId(MBID $workId): Term
    {
        return $this->addTerm((string) $workId, self::workId());
    }
}
