<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\AreaType;

trait AreaTypeTrait
{
    use AbstractAdderTrait;

    /**
     * Adds the area type.
     *
     * @param AreaType $areaType The area type
     *
     * @return Term
     */
    public function addAreaType(AreaType $areaType): Term
    {
        return $this->addTerm((string)$areaType, self::areaType());
    }

    /**
     * Returns the field name for the area type.
     *
     * @return string
     */
    public static function areaType(): string
    {
        return 'type';
    }
}
