<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Count;

trait NumberOfReleasesTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the number of releases.
     *
     * @return string
     */
    public static function numberOfReleases(): string
    {
        return 'releases';
    }

    /**
     * Adds the number of releases in this release group.
     *
     * @param Count $numberOfReleases The number of releases in the release group
     *
     * @return Term
     */
    public function addNumberOfReleases(Count $numberOfReleases): Term
    {
        return $this->addTerm((string) $numberOfReleases, self::numberOfReleases());
    }
}
