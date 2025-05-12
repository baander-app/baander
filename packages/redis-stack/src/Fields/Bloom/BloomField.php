<?php

namespace Baander\RedisStack\Fields\Bloom;

use Baander\RedisStack\Fields\Field;

class BloomField extends Field
{
    private string $operation; // Defaults to `BF.EXISTS`
    private string $value;

    public function __construct(string $fieldName, string $value, string $operation = 'BF.EXISTS')
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