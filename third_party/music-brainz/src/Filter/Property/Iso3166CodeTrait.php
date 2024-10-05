<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\ISO3166Code;

trait Iso3166CodeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the ISO 3166-1/2/3 code.
     *
     * @return string
     */
    public static function iso3166Code(): string
    {
        return 'iso';
    }

    /**
     * Adds the ISO 3166-1/2/3 code.
     *
     * @param ISO3166Code $iso3166Code The ISO 3166-1/2/3 code
     *
     * @return Term
     */
    public function addIso3166Code(ISO3166Code $iso3166Code): Term
    {
        return $this->addTerm((string) $iso3166Code, self::iso3166Code());
    }
}
