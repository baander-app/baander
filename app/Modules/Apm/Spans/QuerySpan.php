<?php

namespace App\Modules\Apm\Spans;

use App\Modules\Apm\Util\SqlQueryParser;
use Elastic\Apm\SpanInterface;

/**
 * Specialized span for database queries
 *
 * This class extends AbstractSpan to add data to database query spans.
 * It provides methods for working with database query spans, including
 * getting the query name, type, and table.
 */
class QuerySpan extends AbstractSpan
{
    /**
     * The SQL query parser
     */
    protected ?SqlQueryParser $sqlParser = null;

    /**
     * Constructor
     *
     * @param SpanInterface|null $span The span to wrap
     */
    public function __construct(?SpanInterface $span = null)
    {
        parent::__construct($span);
    }

    /**
     * Set the SQL query
     *
     * @param string $sql The SQL query
     * @return self
     */
    public function setSql(string $sql): self
    {
        $this->sqlParser = new SqlQueryParser($sql);
        return $this;
    }

    /**
     * Get the SQL query
     *
     * @return string|null The SQL query
     */
    public function getSql(): ?string
    {
        return $this->sqlParser?->getSql();
    }

    /**
     * Get the query type
     *
     * @return string|null The query type
     */
    public function getQueryType(): ?string
    {
        return $this->sqlParser?->getQueryType();
    }

    /**
     * Get the table names
     *
     * @return array The array of table names
     */
    public function getTableNames(): array
    {
        return $this->sqlParser ? $this->sqlParser->getTableNames() : [];
    }

    /**
     * Get the primary table name (first table in the query)
     *
     * @return string|null The primary table name or null if no tables found
     */
    public function getTableName(): ?string
    {
        return $this->sqlParser?->getTableName();
    }

    /**
     * Get the name of the query span
     *
     * This method returns a concise, human-readable description of the SQL query,
     * combining the query type and table names (e.g., "SELECT users, orders").
     *
     * @return string The query name
     */
    public function getName(): string
    {
        return $this->sqlParser ? $this->sqlParser->getName() : 'QUERY';
    }
}