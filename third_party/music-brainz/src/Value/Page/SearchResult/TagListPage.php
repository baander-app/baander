<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Page\SearchResult;

use MusicBrainz\Value\Page;
use MusicBrainz\Value\SearchResult\Tag;

/**
 * A list of values
 */
class TagListPage extends Page
{
    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Tag::class;
    }
}
