<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Count;

trait NumberOfDiscIdsOnMediumTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the number of disc IDs on a medium.
     *
     * @return string
     */
    public static function numberOfDiscIdsOnMedium(): string
    {
        return 'discids';
    }

    /**
     * Adds the number of cd ids for the release on a medium in the release .
     *
     * @param Count $numberOfDiscIdsOnMedium The number of cd ids for the release on a medium in the release
     *
     * @return Term
     */
    public function addNumberOfDiscIdsOnMedium(Count $numberOfDiscIdsOnMedium): Term
    {
        return $this->addTerm((string) $numberOfDiscIdsOnMedium, self::numberOfDiscIdsOnMedium());
    }
}
