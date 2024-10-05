<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Date;

trait EndDateTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the ending date.
     *
     * @return string
     */
    public static function endDate(): string
    {
        return 'end';
    }

    /**
     * Adds the ending date.
     *
     * @param Date $endDate The ending date
     *
     * @return Term
     */
    public function addEndDate(Date $endDate): Term
    {
        return $this->addTerm((string) $endDate, self::endDate());
    }
}
