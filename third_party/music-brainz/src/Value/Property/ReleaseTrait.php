<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Release;

use function is_null;

/**
 * Provides a getter for a release.
 */
trait ReleaseTrait
{
    /**
     * The release number
     *
     * @var Release
     */
    public Release $release;

    /**
     * Returns the release.
     *
     * @return Release
     */
    public function getRelease(): Release
    {
        return $this->release;
    }

    /**
     * Sets the release by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     * @param null|string $key   Optional array key (default: "release")
     *                           Use first array dimension for null
     *
     * @return void
     */
    private function setReleaseFromArray(array $input, ?string $key = 'release'): void
    {
        if (is_null($key)) {
            $this->release = new Release($input);

            return;
        }

        $this->release = is_null($release = ArrayAccess::getArray($input, $key))
            ? new Release()
            : new Release($release);
    }
}
