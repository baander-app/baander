<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Page;

use MusicBrainz\Value\Event;
use MusicBrainz\Value\Page;

/**
 * A list of values
 */
class EventListPage extends Page
{
    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Event::class;
    }
}
