<?php

namespace Baander\RedisStack\Search\Query\Filters;

class TagFilter
{
    public function __construct(
        private readonly string $field,
        private readonly array  $values,
        private readonly array $charactersToEscape = [' ', '-']
    ) {}

    public function __toString(): string
    {
        $escapedValues = array_map(
            fn($value) => str_replace($this->charactersToEscape, '\\\\', $value),
            $this->values
        );
        return sprintf('@%s:{%s}', $this->field, implode('|', $escapedValues));
    }
}