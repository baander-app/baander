<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Length;

trait LengthTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the length.
     *
     * @return string
     */
    public static function length(): string
    {
        return 'dur';
    }

    /**
     * Adds the length.
     *
     * @param Length $length The Length
     *
     * @return Term
     */
    public function addLength(Length $length): Term
    {
        return $this->addTerm((string) $length, self::length());
    }
}
