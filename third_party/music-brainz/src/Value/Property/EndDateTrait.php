<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Date;

use function is_null;

/**
 * Provides a getter for the ending date.
 */
trait EndDateTrait
{
    /**
     * The end date indicate when an entity finished its existence. For artists, its exact meaning depends on the type
     * of artist:
     *
     * - For a person: End date represents date of death. If the person is still alive, the end date is null.
     * - For a group (or orchestra/choir): End date represents the date when the group last dissolved. If a group
     *   dissolved and then reunited, the date is that of when they last dissolved. If they are together, it is null.
     * - For a character: The end date is not set, since new media featuring a character can be created at any time. In
     *   particular, the end date field ist not used to hold the fictional death date of a character.
     * - For others: There are no clear indications about how to use dates for artists of the type "Other" at the moment.
     *
     * @var Date
     */
    private Date $endDate;

    /**
     * Returns the ending date.
     *
     * @return Date
     */
    public function getEndDate(): Date
    {
        return $this->endDate;
    }

    /**
     * Sets the ending date by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setEndDateFromArray(array $input): void
    {
        $this->endDate = is_null($endDate = ArrayAccess::getString($input, 'end'))
            ? new Date()
            : new Date($endDate);
    }
}
