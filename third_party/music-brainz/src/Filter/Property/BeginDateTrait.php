<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Date;

trait BeginDateTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the beginning date.
     *
     * @return string
     */
    public static function beginDate(): string
    {
        return 'begin';
    }

    /**
     * Adds the beginning date.
     *
     * @param Date $beginDate The beginning date
     *
     * @return Term
     */
    public function addBeginDate(Date $beginDate): Term
    {
        return $this->addTerm((string) $beginDate, self::beginDate());
    }
}
