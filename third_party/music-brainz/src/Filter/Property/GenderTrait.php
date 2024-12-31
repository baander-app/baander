<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Gender;

trait GenderTrait
{
    use AbstractAdderTrait;

    /**
     * Adds a gender.
     *
     * @param Gender $gender A gender
     *
     * @return Term
     */
    public function addGender(Gender $gender): Term
    {
        return $this->addTerm((string)$gender, self::gender());
    }

    /**
     * Returns the field name for the gender.
     *
     * @return string
     */
    public static function gender(): string
    {
        return 'gender';
    }
}
