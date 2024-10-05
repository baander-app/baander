<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\SectorOffsetList;
use MusicBrainz\Value\Sectors;

use function is_null;

/**
 * Provides a getter for a list of sector offsets.
 */
trait SectorOffsetsTrait
{
    /**
     * A list of sector offsets
     *
     * @var Sectors[]|SectorOffsetList
     */
    private SectorOffsetList $sectorOffsets;

    /**
     * Returns a list of sector offsets.
     *
     * @return Sectors[]|SectorOffsetList
     */
    public function getSectorOffsets(): SectorOffsetList
    {
        return $this->sectorOffsets;
    }

    /**
     * Sets the list of sector offsets by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setSectorOffsetsFromArray(array $input): void
    {
        $this->sectorOffsets = is_null($sectorOffsets = ArrayAccess::getArray($input, 'offsets'))
            ? new SectorOffsetList()
            : new SectorOffsetList($sectorOffsets);
    }
}
