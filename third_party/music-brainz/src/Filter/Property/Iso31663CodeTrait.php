<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\ISO31663Code;

trait Iso31663CodeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the ISO 3166-3 code.
     *
     * @return string
     */
    public static function iso31663Code(): string
    {
        return 'iso';
    }

    /**
     * Adds the ISO 3166-3 code.
     *
     * @param ISO31663Code $iso31663Code The ISO 3166-3 code
     *
     * @return Term
     */
    public function addIso31663Code(ISO31663Code $iso31663Code): Term
    {
        return $this->addTerm((string) $iso31663Code, self::iso31663Code());
    }
}
