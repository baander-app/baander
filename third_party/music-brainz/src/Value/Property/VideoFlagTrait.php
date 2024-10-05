<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\VideoFlag;

use function is_null;

/**
 * Provides a getter for the "video" flag.
 */
trait VideoFlagTrait
{
    /**
     * The "video" flag
     *
     * @var VideoFlag
     */
    private VideoFlag $videoFlag;

    /**
     * Returns the "video" flag.
     *
     * @return VideoFlag
     */
    public function getVideoFlag(): VideoFlag
    {
        return $this->videoFlag;
    }

    /**
     * Sets the "video" flag by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setVideoFlagFromArray(array $input): void
    {
        $this->videoFlag = is_null($videoFlag = ArrayAccess::getBool($input, 'video'))
            ? new VideoFlag()
            : new VideoFlag($videoFlag);
    }
}
