<?php

namespace Baander\RedisStack\Query;

use Baander\RedisStack\Result\SearchResult;
use InvalidArgumentException;
use Redis;

class QueryBuilder
{
    private const GEO_FILTER_UNITS = ['m', 'km', 'mi', 'ft'];

    protected string $query = '*';
    protected array $tagFilters = [];
    protected array $numericFilters = [];
    protected array $geoFilters = [];
    protected ?string $sortByField = null;
    protected string $sortOrder = 'ASC';
    protected ?int $offset = null;
    protected ?int $pageSize = null;

    // RedisSearch-specific directives
    protected ?string $return = null;
    protected ?string $summarize = null;
    protected ?string $highlight = null;
    protected ?string $payload = null;
    protected ?string $scorer = null;
    protected ?string $language = null;
    protected array $options = [];

    private Redis $redis;
    private string $indexName;

    public function __construct(Redis $redis, string $indexName)
    {
        $this->redis = $redis;
        $this->indexName = $indexName;
    }

    /**
     * Set the query string for full-text search.
     */
    public function query(string $query): self
    {
        if (empty($query)) {
            throw new InvalidArgumentException('Query cannot be empty.');
        }
        $this->query = $query;
        return $this;
    }

    /**
     * Add a tag filter for exact matches.
     */
    public function tagFilter(string $field, array $values): self
    {
        $escaped = array_map(fn($v) => str_replace([' ', '-', '|'], ['\\ ', '\\-', '\\|'], $v), $values);
        $tagFilter = sprintf('@%s:{%s}', $field, implode('|', $escaped));
        $this->tagFilters[] = $tagFilter;
        return $this;
    }

    /**
     * Add a numeric range filter.
     */
    public function numericFilter(string $field, float|int $min, float|int|null $max = null): self
    {
        $this->numericFilters[] = sprintf('@%s:[%s %s]', $field, $min, $max ?? '+inf');
        return $this;
    }

    /**
     * Add a geo-spatial filter.
     */
    public function geoFilter(string $field, float $lon, float $lat, float $radius, string $unit = 'km'): self
    {
        if (!in_array($unit, self::GEO_FILTER_UNITS, true)) {
            throw new InvalidArgumentException("Invalid geo unit: $unit");
        }
        $this->geoFilters[] = sprintf('@%s:[%s %s %s %s]', $field, $lon, $lat, $radius, $unit);
        return $this;
    }

    /**
     * Add sorting.
     */
    public function sortBy(string $field, string $order = 'ASC'): self
    {
        $this->sortByField = $field;
        $this->sortOrder = $order;
        return $this;
    }

    /**
     * Set result limits for pagination.
     */
    public function limit(int $offset, int $pageSize = 10): self
    {
        $this->offset = $offset;
        $this->pageSize = $pageSize;
        return $this;
    }

    /**
     * Add fields to return in the result.
     */
    public function returnFields(array $fields): self
    {
        $this->return = sprintf('RETURN %d %s', count($fields), implode(' ', $fields));
        return $this;
    }

    /**
     * Add highlighting to specific fields.
     */
    public function highlight(array $fields, string $openTag = '<strong>', string $closeTag = '</strong>'): self
    {
        $this->highlight = sprintf(
            'HIGHLIGHT FIELDS %d %s TAGS %s %s',
            count($fields),
            implode(' ', $fields),
            $openTag,
            $closeTag
        );
        return $this;
    }

    /**
     * Add summarization to specific fields.
     */
    public function summarize(array $fields, int $fragments = 3, int $length = 50, string $separator = '...'): self
    {
        $this->summarize = sprintf(
            'SUMMARIZE FIELDS %d %s FRAGS %d LEN %d SEPARATOR %s',
            count($fields),
            implode(' ', $fields),
            $fragments,
            $length,
            $separator
        );
        return $this;
    }

    /**
     * Add RedisSearch-specific directives.
     */
    public function withScores(): self
    {
        $this->options[] = 'WITHSCORES';
        return $this;
    }

    public function noContent(): self
    {
        $this->options[] = 'NOCONTENT';
        return $this;
    }

    public function noStopWords(): self
    {
        $this->options[] = 'NOSTOPWORDS';
        return $this;
    }

    /**
     * Build the Redis command arguments dynamically.
     */
    private function buildSearchCommand(): array
    {
        $filters = array_merge($this->tagFilters, $this->numericFilters, $this->geoFilters);
        $query = $filters ? sprintf('%s %s', $this->query, implode(' ', $filters)) : $this->query;

        $command = ['FT.SEARCH', $this->indexName, $query];

        // Add sorting
        if ($this->sortByField) {
            $command[] = 'SORTBY';
            $command[] = $this->sortByField;
            $command[] = $this->sortOrder;
        }

        // Add pagination
        if ($this->offset !== null && $this->pageSize !== null) {
            $command[] = 'LIMIT';
            $command[] = $this->offset;
            $command[] = $this->pageSize;
        }

        // Add RedisSearch options
        $command = array_merge($command, $this->options);

        // Add specific query directives (return, highlight, summarize, etc.)
        if ($this->return) {
            $command[] = $this->return;
        }
        if ($this->highlight) {
            $command[] = $this->highlight;
        }
        if ($this->summarize) {
            $command[] = $this->summarize;
        }

        return $command;
    }

    /**
     * Execute the query.
     */
    public function search(): SearchResult
    {
        $command = $this->buildSearchCommand();
        $rawResult = $this->redis->rawCommand(...$command);

        if (!$rawResult) {
            return new SearchResult(0, []);
        }

        return SearchResult::makeSearchResult($rawResult, false);
    }
}