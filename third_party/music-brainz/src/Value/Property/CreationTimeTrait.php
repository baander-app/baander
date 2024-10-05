<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\TimeStamp;

use function is_null;

/**
 * Provides a getter for the creation time.
 */
trait CreationTimeTrait
{
    /**
     * The creation time
     *
     * @var TimeStamp
     */
    private TimeStamp $creationTime;

    /**
     * Returns the creation time.
     *
     * @return TimeStamp
     */
    public function getCreationTime(): TimeStamp
    {
        return $this->creationTime;
    }

    /**
     * Sets the creation time.
     *
     * @param string $creationTime Creation time
     */
    private function setCreationTime(string $creationTime): void
    {
        $this->creationTime = new TimeStamp($creationTime);
    }

    /**
     * Sets the creation time by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setCreationTimeFromArray(array $input): void
    {
        $this->creationTime = is_null($creationTime = ArrayAccess::getString($input, 'created'))
            ? new TimeStamp()
            : new TimeStamp($creationTime);
    }
}
