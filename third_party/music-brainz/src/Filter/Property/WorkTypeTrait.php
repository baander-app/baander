<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\WorkType;

trait WorkTypeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the type of work.
     *
     * @return string
     */
    public static function workType(): string
    {
        return 'type';
    }

    /**
     * Adds the type of work.
     *
     * @param WorkType $workType The type of work
     *
     * @return Term
     */
    public function addWorkType(WorkType $workType): Term
    {
        return $this->addTerm((string) $workType, self::workType());
    }
}
