<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\MBID;

trait AreaIdTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the area ID.
     *
     * @return string
     */
    public static function areaId(): string
    {
        return 'aid';
    }

    /**
     * Adds the MusicBrainz Identifier (MBID) of an area.
     *
     * @param MBID $areaId The MusicBrainz Identifier (MBID) of an area
     *
     * @return Term
     */
    public function addAreaId(MBID $areaId): Term
    {
        return $this->addTerm((string) $areaId, self::areaId());
    }
}
