<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\ISO31661Code;

trait Iso31661CodeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the ISO 3166-1 code.
     *
     * @return string
     */
    public static function iso31661Code(): string
    {
        return 'iso';
    }

    /**
     * Adds the ISO 3166-1 code.
     *
     * @param ISO31661Code $iso31661Code The ISO 3166-1 code
     *
     * @return Term
     */
    public function addIso31661Code(ISO31661Code $iso31661Code): Term
    {
        return $this->addTerm((string) $iso31661Code, self::iso31661Code());
    }
}
