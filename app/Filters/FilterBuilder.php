<?php

namespace App\Filters;

use App\Support\BaseBuilder;
use Tpetry\PostgresqlEnhanced\Query\Builder;

class FilterBuilder
{
    protected BaseBuilder $query;
    protected $filters;
    protected string $namespace;

    public function __construct($query, $filters, $namespace)
    {
        $this->query = $query;
        $this->filters = $filters;
        $this->namespace = $namespace;
    }

    public function apply()
    {
        foreach ($this->filters as $name => $value) {
            $normalizedName = ucfirst($name);
            $class = $this->namespace . "\\{$normalizedName}";

            if (!class_exists($class)) {
                continue;
            }

            if (strlen($value)) {
                (new $class($this->query))->handle($value);
            } else {
                (new $class($this->query))->handle();
            }
        }

        return $this->query;
    }
}