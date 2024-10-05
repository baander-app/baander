<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\ASIN;

trait AsinTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the ASIN.
     *
     * @return string
     */
    public static function asin(): string
    {
        return 'asin';
    }

    /**
     * Adds the Amazon Standard Identification Number (ASIN).
     *
     * @param ASIN $asin The Amazon Standard Identification Number (ASIN)
     *
     * @return Term
     */
    public function addASIN(ASIN $asin): Term
    {
        return $this->addTerm((string) $asin, self::asin());
    }
}
