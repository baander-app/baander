<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Page;

use MusicBrainz\Value\Page;
use MusicBrainz\Value\ReleaseGroup;

/**
 * A list of values
 */
class ReleaseGroupListPage extends Page
{
    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return ReleaseGroup::class;
    }
}
