<?php

namespace Baander\RedisStack\Fields\Bloom;

use Baander\RedisStack\Fields\Field;

class CuckooField extends Field
{
    private string $operation; // Default is `CF.EXISTS`
    private string $value;

    public function __construct(string $fieldName, string $value, string $operation = 'CF.EXISTS')
    {
        parent::__construct($fieldName);
        $this->operation = $operation;
        $this->value = $value;
    }

    public function __toString(): string
    {
        return sprintf('%s %s %s', $this->operation, $this->fieldName, $this->value);
    }
}