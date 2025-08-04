<?php

namespace Baander\RedisStack\Index;

use Baander\RedisStack\Query\QueryBuilder;
use Baander\RedisStack\Result\SearchResult;
use Exception;
use InvalidArgumentException;

class Index extends AbstractIndex implements IndexInterface
{
    /** @var FieldDefinition[] */
    protected array $fieldDefinitions = [];
    protected ?string $prefix = null;
    protected ?array $stopWords = null;

    public function create(): void
    {
        if (empty($this->fieldDefinitions)) {
            throw new InvalidArgumentException('Index requires at least one field!');
        }

        $schema = [$this->getIndexName(), 'ON', 'HASH'];

        if ($this->prefix) {
            $schema[] = 'PREFIX';
            $schema[] = 1;
            $schema[] = $this->prefix;
        }

        $schema[] = 'SCHEMA';
        foreach ($this->fieldDefinitions as $field) {
            $schema = array_merge($schema, $field->getSchema());
        }

        $this->rawCommand('FT.CREATE', $schema);
    }

    public function drop(): void
    {
        $this->rawCommand('FT.DROPINDEX', [$this->getIndexName()]);
    }

    public function exists(): bool
    {
        try {
            $this->rawCommand('FT.INFO', [$this->getIndexName()]);
            return true;
        } catch (Exception) {
            return false;
        }
    }

    public function addField(FieldDefinition $field): self
    {
        $this->fieldDefinitions[] = $field;
        return $this;
    }

    public function search(string $query): SearchResult
    {
        $this->buildQuery()->search($query);

        $response = $this->rawCommand('FT.SEARCH', [$this->getIndexName(), $query]);
        return new SearchResult($response);
    }

    public function buildQuery(): QueryBuilder
    {
        return new QueryBuilder($this->redis, $this->getIndexName());
    }

    /**
     * @return string
     */
    public function getIndexName(): string
    {
        return$this->indexName === '' ? self::class : $this->indexName;
    }

}