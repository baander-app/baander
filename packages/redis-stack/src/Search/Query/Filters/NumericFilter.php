<?php

namespace Baander\RedisStack\Search\Query\Filters;

class NumericFilter
{
    public function __construct(
        private readonly string           $field,
        private readonly string|int|float $min,
        private readonly string|int|float|null $max = null
    ) {}

    public function __toString(): string
    {
        return sprintf('@%s:[%s %s]', $this->field, $this->min, $this->max ?? 'inf');
    }
}