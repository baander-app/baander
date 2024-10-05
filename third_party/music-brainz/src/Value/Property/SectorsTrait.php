<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Sectors;

use function is_null;

/**
 * Provides a getter for a number of sectors.
 */
trait SectorsTrait
{
    /**
     * The number of sectors
     *
     * @var Sectors
     */
    private Sectors $sectors;

    /**
     * Returns the number of sectors.
     *
     * @return Sectors
     */
    public function getSectors(): Sectors
    {
        return $this->sectors;
    }

    /**
     * Sets the sectors by extracting them from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setSectorsFromArray(array $input): void
    {
        $this->sectors = is_null($sectors = ArrayAccess::getInteger($input, 'sectors'))
            ? new Sectors()
            : new Sectors($sectors);
    }
}
