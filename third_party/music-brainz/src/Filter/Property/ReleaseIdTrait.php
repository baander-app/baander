<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\MBID;

trait ReleaseIdTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the language.
     *
     * @return string
     */
    public static function releaseId(): string
    {
        return 'reid';
    }

    /**
     * Adds the MusicBrainz ID of a release.
     *
     * @param MBID $releaseId The MusicBrainz ID of a release
     *
     * @return Term
     */
    public function addReleaseId(MBID $releaseId): Term
    {
        return $this->addTerm((string) $releaseId, self::releaseId());
    }
}
