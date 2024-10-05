<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Date;

use function is_null;

/**
 * Provides a getter for the beginning date.
 */
trait BeginDateTrait
{
    /**
     * The beginning date indicates when an entity started its existence. For artists, its exact meaning depends on the
     * type of artist:
     *
     * - For a person: Begin date represents date of birth
     * - For a group (or orchestra/choir): Begin date represents the date when the group first formed. If a group
     *   dissolved and then reunited, the date is still that of when they first formed.
     * - For a character: Begin date represents the date (in real life) when the character concept was created. In
     *   particular, the begin date is not used to hold the fictional birth date of a character.
     * - For others: There are no clear indications about how to use dates for artists of the type "Other" at the moment.
     *
     * @var Date
     */
    private Date $beginDate;

    /**
     * Returns the beginning date.
     *
     * @return Date
     */
    public function getBeginDate(): Date
    {
        return $this->beginDate;
    }

    /**
     * Sets the beginning date by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setBeginDateFromArray(array $input): void
    {
        $this->beginDate = is_null($beginDate = ArrayAccess::getString($input, 'begin'))
            ? new Date()
            : new Date($beginDate);
    }
}
