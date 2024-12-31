<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\PlaceType;

trait PlaceTypeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the type of place.
     *
     * @return string
     */
    public static function placeType(): string
    {
        return 'type';
    }

    /**
     * Adds the type of place.
     *
     * @param PlaceType $placeType The type of place
     *
     * @return Term
     */
    public function addPlaceType(PlaceType $placeType): Term
    {
        return $this->addTerm((string) $placeType, self::placeType());
    }
}
