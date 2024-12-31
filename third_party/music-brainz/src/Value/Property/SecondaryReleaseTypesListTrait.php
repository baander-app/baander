<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\ReleaseType;
use MusicBrainz\Value\ReleaseTypeList;

use function is_null;

/**
 * Provides a getter for a list of secondary release types.
 */
trait SecondaryReleaseTypesListTrait
{
    /**
     * A list of secondary release types
     *
     * @var ReleaseType[]|ReleaseTypeList
     */
    private ReleaseTypeList $secondaryReleaseTypes;

    /**
     * Returns the list of secondary release types.
     *
     * @return ReleaseType[]|ReleaseTypeList
     */
    public function getSecondaryReleaseTypes(): ReleaseTypeList
    {
        return $this->secondaryReleaseTypes;
    }

    /**
     * Sets the list of secondary release types by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setSecondaryReleaseTypesFromArray(array $input): void
    {
        $this->secondaryReleaseTypes = is_null($secondaryReleaseTypes = ArrayAccess::getArray($input, 'secondary-types'))
            ? new ReleaseTypeList()
            : new ReleaseTypeList($secondaryReleaseTypes);
    }
}
