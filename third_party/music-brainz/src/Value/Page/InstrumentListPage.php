<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Page;

use MusicBrainz\Value\Instrument;
use MusicBrainz\Value\Page;

/**
 * A list of values
 */
class InstrumentListPage extends Page
{
    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Instrument::class;
    }
}
