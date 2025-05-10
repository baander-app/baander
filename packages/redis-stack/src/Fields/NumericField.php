<?php

namespace Baander\RedisStack\Fields;

class NumericField extends Field
{
    private string|int|float $min;
    private string|int|float $max;

    public function __construct(string $fieldName, string|int|float $min, string|int|float $max = '+inf')
    {
        parent::__construct($fieldName);
        $this->min = $min;
        $this->max = $max != null ? $max : '+inf';
    }

    public function __toString(): string
    {
        return sprintf('@%s:[%s %s]', $this->fieldName, $this->min, $this->max);
    }
}