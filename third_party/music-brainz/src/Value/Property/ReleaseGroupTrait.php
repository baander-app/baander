<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\ReleaseGroup;

use function is_null;

/**
 * Provides a getter for a release group.
 */
trait ReleaseGroupTrait
{
    /**
     * The release group
     *
     * @var ReleaseGroup
     */
    public ReleaseGroup $releaseGroup;

    /**
     * Returns the release.
     *
     * @return ReleaseGroup
     */
    public function getReleaseGroup(): ReleaseGroup
    {
        return $this->releaseGroup;
    }

    /**
     * Sets a release group by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setReleaseGroupFromArray(array $input): void
    {
        $this->releaseGroup = is_null($releaseGroup = ArrayAccess::getArray($input, 'release-group'))
            ? new ReleaseGroup()
            : new ReleaseGroup($releaseGroup);
    }
}
