<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\MBID;

trait ReleaseGroupIdTrait
{
    use AbstractAdderTrait;

    /**
     * Adds the MusicBrainz ID (MBID) of the release group.
     *
     * @param MBID $releaseGroupId The MusicBrainz ID (MBID) of the release group
     *
     * @return Term
     */
    public function addReleaseGroupId(MBID $releaseGroupId): Term
    {
        return $this->addTerm((string)$releaseGroupId, self::releaseGroupId());
    }

    /**
     * Returns the field name for the release group.
     *
     * @return string
     */
    public static function releaseGroupId(): string
    {
        return 'rgid';
    }
}
