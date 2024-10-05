<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\AreaType;

use function is_null;

/**
 * Provides a getter for the area type.
 */
trait AreaTypeTrait
{
    /**
     * An area type
     *
     * @var AreaType
     */
    private AreaType $areaType;

    /**
     * Returns the area type.
     *
     * @return AreaType
     */
    public function getAreaType(): AreaType
    {
        return $this->areaType;
    }

    /**
     * Sets the area type by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setAreaTypeFromArray(array $input): void
    {
        $this->areaType = is_null($areaType = ArrayAccess::getString($input, 'type'))
            ? new AreaType()
            : new AreaType($areaType);
    }
}
