<?php

namespace Baander\RedisStack\Search;

use Redis;

class SearchManager
{
    public function __construct(private readonly Redis $redis) {}

    /**
     * Execute a search query using a fluent query builder.
     */
    public function query(string $indexName): SearchQuery
    {
        return new SearchQuery($this->redis, $indexName);
    }
}