<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Count;

trait NumberOfMediumsTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the number of mediums.
     *
     * @return string
     */
    public static function numberOfMediums(): string
    {
        return 'mediums';
    }

    /**
     * Adds the number of mediums.
     *
     * @param Count $numberOfMediums The number of mediums
     *
     * @return Term
     */
    public function addNumberOfMediums(Count $numberOfMediums): Term
    {
        return $this->addTerm((string) $numberOfMediums, self::numberOfMediums());
    }
}
