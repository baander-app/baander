<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Date;

trait DateTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the date.
     *
     * @return string
     */
    public static function date(): string
    {
        return 'date';
    }

    /**
     * Adds the recording release date.
     *
     * @param Date $date The recording release date
     *
     * @return Term
     */
    public function addDate(Date $date): Term
    {
        return $this->addTerm((string) $date, self::date());
    }
}
