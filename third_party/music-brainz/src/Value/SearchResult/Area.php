<?php

declare(strict_types=1);

namespace MusicBrainz\Value\SearchResult;

use MusicBrainz\Value\Property\AreaTrait;
use MusicBrainz\Value\SearchResult;

/**
 * A list of results from the search API
 */
class Area extends SearchResult
{
    use AreaTrait;

    /**
     * Constructs a list of results from the search API.
     *
     * @param array $searchResult A search result from the search API
     */
    public function __construct(array $searchResult = [])
    {
        $this->setAreaFromArray($searchResult, null);
        parent::__construct($searchResult);
    }

    /**
     * Returns the search result as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getArea();
    }
}
