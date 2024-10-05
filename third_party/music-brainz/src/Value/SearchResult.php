<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A result from the search API
 */
abstract class SearchResult implements Value
{
    use Property\ScoreTrait;

    /**
     * Constructs a result from the search API.
     *
     * @param array $searchResult Information about the result from the search API
     */
    public function __construct(array $searchResult = [])
    {
        $this->score = isset($searchResult['score']) ? new Score((int) $searchResult['score']) : new Score();
    }
}
