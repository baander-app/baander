<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\FirstReleaseDate;

use function is_null;

/**
 * Provides a getter for the first release date.
 */
trait FirstReleaseDateTrait
{
    /**
     * The first release date
     *
     * @var FirstReleaseDate
     */
    public FirstReleaseDate $firstReleaseDate;

    /**
     * Returns the first release date.
     *
     * @return FirstReleaseDate
     */
    public function getFirstReleaseDate(): FirstReleaseDate
    {
        return $this->firstReleaseDate;
    }

    /**
     * Sets the first release date by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setFirstReleaseDateFromArray(array $input): void
    {
        $this->firstReleaseDate = is_null($firstReleaseDate = ArrayAccess::getString($input, 'first-release-date'))
            ? new FirstReleaseDate()
            : new FirstReleaseDate($firstReleaseDate);
    }
}
