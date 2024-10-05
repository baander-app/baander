<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait AreaNameTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the area name.
     *
     * @return string
     */
    public static function areaName(): string
    {
        return 'area';
    }

    /**
     * Adds an area name.
     *
     * @param Name $areaName An area name
     *
     * @return Phrase
     */
    public function addAreaName(Name $areaName): Phrase
    {
        return $this->addPhrase((string) $areaName, self::areaName());
    }
}
