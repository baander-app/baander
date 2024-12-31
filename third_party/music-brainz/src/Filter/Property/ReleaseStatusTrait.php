<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\ReleaseStatus;

trait ReleaseStatusTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the release status.
     *
     * @return string
     */
    public static function releaseStatus(): string
    {
        return 'status';
    }

    /**
     * Adds the release status.
     *
     * @param ReleaseStatus $releaseStatus The release status
     *
     * @return Term
     */
    public function addReleaseStatus(ReleaseStatus $releaseStatus): Term
    {
        return $this->addTerm((string) $releaseStatus, self::releaseStatus());
    }
}
