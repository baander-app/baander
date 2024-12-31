<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\WorkType;

use function is_null;

/**
 * Provides a getter for the type of work.
 */
trait WorkTypeTrait
{
    /**
     * A type of work
     *
     * @var WorkType
     */
    private WorkType $workType;

    /**
     * Returns type of work.
     *
     * @return WorkType
     */
    public function getWorkType(): WorkType
    {
        return $this->workType;
    }

    /**
     * Sets the type of work by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setWorkTypeFromArray(array $input): void
    {
        $this->workType = is_null($workType = ArrayAccess::getString($input, 'type'))
            ? new WorkType()
            : new WorkType($workType);
    }
}
