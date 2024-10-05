<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;

trait EndedTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the "ended" flag.
     *
     * @return string
     */
    public static function ended(): string
    {
        return 'ended';
    }

    /**
     * Adds a flag indicating whether or not the searched entity has ended.
     *
     * @param bool $ended A flag indicating whether or not the searched entity has ended
     *
     * @return Term
     */
    public function addEnded(bool $ended): Term
    {
        return $this->addTerm((string) $ended, self::ended());
    }
}
