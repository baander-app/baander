<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\ReleaseGroup;
use MusicBrainz\Value\ReleaseGroupList;

use function is_null;

/**
 * Provides a getter for a list of release groups.
 */
trait ReleaseGroupsTrait
{
    /**
     * A list of release groups
     *
     * @var ReleaseGroup[]|ReleaseGroupList
     */
    private ReleaseGroupList $releaseGroups;

    /**
     * Returns a list of release groups.
     *
     * @return ReleaseGroup[]|ReleaseGroupList
     */
    public function getReleaseGroups(): ReleaseGroupList
    {
        return $this->releaseGroups;
    }

    /**
     * Sets the release groups by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setReleaseGroupsFromArray(array $input): void
    {
        $this->releaseGroups = is_null($releaseGroups = ArrayAccess::getArray($input, 'release-groups'))
            ? new ReleaseGroupList()
            : new ReleaseGroupList($releaseGroups);
    }
}
