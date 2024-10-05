<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of sector offsets
 */
class SectorOffsetList extends ValueList
{
    /**
     * Constructs a list of sectors.
     *
     * @param array $sectorss Information about the sector offsets
     */
    public function __construct(array $sectorss = [])
    {
        parent::__construct(
            array_map(
                function ($sectors) {
                    return new Sectors($sectors);
                },
                $sectorss
            )
        );
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Sectors::class;
    }
}
