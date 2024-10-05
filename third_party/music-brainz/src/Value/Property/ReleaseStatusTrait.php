<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\ReleaseStatus;

/**
 * Provides a getter for a release status.
 */
trait ReleaseStatusTrait
{
    /**
     * The release status
     *
     * @var ReleaseStatus
     */
    private ReleaseStatus $releaseStatus;

    /**
     * Returns the release status.
     *
     * @return ReleaseStatus
     */
    public function getReleaseStatus(): ReleaseStatus
    {
        return $this->releaseStatus;
    }

    /**
     * Sets the release status by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setReleaseStatusFromArray(array $input): void
    {
        $releaseStatus   = ArrayAccess::getString($input, 'status');
        $releaseStatusId = ArrayAccess::getString($input, 'status-id');

        $this->releaseStatus = new ReleaseStatus(
            $releaseStatus ?? ReleaseStatus::UNDEFINED,
            isset($releaseStatusId) ? new MBID($releaseStatusId) : null
        );
    }
}
