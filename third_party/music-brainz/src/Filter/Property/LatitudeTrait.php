<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Latitude;

trait LatitudeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the latitude.
     *
     * @return string
     */
    public static function latitude(): string
    {
        return 'latitude';
    }

    /**
     * Adds the latitude.
     *
     * @param Latitude $latitude The latitude
     *
     * @return Term
     */
    public function addLatitude(Latitude $latitude): Term
    {
        return $this->addTerm((string) $latitude, self::latitude());
    }
}
