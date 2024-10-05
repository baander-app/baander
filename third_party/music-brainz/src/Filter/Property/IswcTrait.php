<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\ISWC;

trait IswcTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the International Standard Musical Work Code (ISWC).
     *
     * @return string
     */
    public static function iswc(): string
    {
        return 'iswc';
    }

    /**
     * Adds the International Standard Musical Work Code (ISWC).
     *
     * @param ISWC $iswc The International Standard Musical Work Code (ISWC)
     *
     * @return Term
     */
    public function addDisambiguationComment(ISWC $iswc): Term
    {
        return $this->addTerm((string) $iswc, self::iswc());
    }
}
