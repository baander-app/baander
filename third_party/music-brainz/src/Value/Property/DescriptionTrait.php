<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Description;

use function is_null;

/**
 * Provides a getter for the description.
 */
trait DescriptionTrait
{
    /**
     * The description
     *
     * @var Description
     */
    private Description $description;

    /**
     * Returns the description.
     *
     * @return Description
     */
    public function getDescription(): Description
    {
        return $this->description;
    }

    /**
     * Sets the description by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setDescriptionFromArray(array $input): void
    {
        $this->description = is_null($description = ArrayAccess::getString($input, 'description'))
            ? new Description()
            : new Description($description);
    }
}
