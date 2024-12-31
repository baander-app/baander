<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Page\SearchResult;

use MusicBrainz\Value\Page;
use MusicBrainz\Value\SearchResult\Label;

/**
 * A list of values
 */
class LabelListPage extends Page
{
    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Label::class;
    }
}
