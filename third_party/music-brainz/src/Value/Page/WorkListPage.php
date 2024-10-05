<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Page;

use MusicBrainz\Value\Page;
use MusicBrainz\Value\Work;

/**
 * A list of values
 */
class WorkListPage extends Page
{
    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Work::class;
    }
}
