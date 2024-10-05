<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\DataQuality;

use function is_null;

/**
 * Provides a getter for the data quality.
 */
trait DataQualityTrait
{
    /**
     * The data quality
     *
     * @var DataQuality
     */
    private DataQuality $quality;

    /**
     * Returns the data quality.
     *
     * @return DataQuality
     */
    public function getQuality(): DataQuality
    {
        return $this->quality;
    }

    /**
     * Sets the data quality by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setDataQualityFromArray(array $input): void
    {
        $this->quality = is_null($quality = ArrayAccess::getString($input, 'quality'))
            ? new DataQuality()
            : new DataQuality($quality);
    }
}
