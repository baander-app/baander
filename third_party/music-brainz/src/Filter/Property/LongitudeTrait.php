<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Longitude;

trait LongitudeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the longitude.
     *
     * @return string
     */
    public static function longitude(): string
    {
        return 'longitude';
    }

    /**
     * Adds the longitude.
     *
     * @param Longitude $longitude The longitude
     *
     * @return Term
     */
    public function addLongitude(Longitude $longitude): Term
    {
        return $this->addTerm((string) $longitude, self::longitude());
    }
}
