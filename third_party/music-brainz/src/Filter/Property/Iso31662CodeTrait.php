<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\ISO31662Code;

trait Iso31662CodeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the ISO 3166-2 code.
     *
     * @return string
     */
    public static function iso31662Code(): string
    {
        return 'iso';
    }

    /**
     * Adds the ISO 3166-2 code.
     *
     * @param ISO31662Code $iso31662Code The ISO 3166-2 code
     *
     * @return Term
     */
    public function addIso31662Code(ISO31662Code $iso31662Code): Term
    {
        return $this->addTerm((string) $iso31662Code, self::iso31662Code());
    }
}
