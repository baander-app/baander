<?php

declare(strict_types=1);

namespace MusicBrainz\Value\SearchResult;

use MusicBrainz\Value\Property\WorkTrait;
use MusicBrainz\Value\SearchResult;

/**
 * A list of results from the search API
 */
class Work extends SearchResult
{
    use WorkTrait;

    /**
     * Constructs a list of results from the search API.
     *
     * @param array $searchResult A search result from the search API
     */
    public function __construct(array $searchResult = [])
    {
        $this->setWorkFromArray($searchResult);
        parent::__construct($searchResult);
    }

    /**
     * Returns the search result as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getWork();
    }
}
