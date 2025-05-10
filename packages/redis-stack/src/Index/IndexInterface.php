<?php

namespace Baander\RedisStack\Index;

use Baander\RedisStack\Query\QueryBuilder;
use Baander\RedisStack\Result\SearchResult;

interface IndexInterface
{
    public function create(): void;

    public function exists(): bool;

    public function drop(): void;

    public function search(string $query): SearchResult;

    public function buildQuery(): QueryBuilder;

    public function addField(FieldDefinition $field): self;
}