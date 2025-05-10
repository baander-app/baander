<?php

namespace Baander\RedisStack\Fields;

class JSONField extends Field
{
    private string $jsonPath;
    private string|int|float $min;
    private string|int|float|null $max;

    public function __construct(string $fieldName, string $jsonPath, string|int|float $min, string|int|float|null $max = null)
    {
        parent::__construct($fieldName);
        $this->jsonPath = $jsonPath;
        $this->min = $min;
        $this->max = $max ?? '+inf';
    }

    public function __toString(): string
    {
        return sprintf('$.%s:%s:[%s %s]', $this->jsonPath, $this->fieldName, $this->min, $this->max);
    }
}