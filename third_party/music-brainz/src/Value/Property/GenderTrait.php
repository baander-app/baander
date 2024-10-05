<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Gender;

use function is_null;

/**
 * Provides a getter for the gender.
 */
trait GenderTrait
{
    /**
     * The gender is used to explicitly state whether a person or character identifies as male, female or neither.
     * Groups have an undefined gender.
     *
     * @var Gender
     */
    private Gender $gender;

    /**
     * Returns the artist's gender.
     *
     * @return Gender
     */
    public function getGender(): Gender
    {
        return $this->gender;
    }

    /**
     * Sets the gender by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setGenderFromArray(array $input): void
    {
        $this->gender = is_null($sortName = ArrayAccess::getString($input, 'gender'))
            ? new Gender()
            : new Gender($sortName);
    }
}
