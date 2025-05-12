<?php

namespace Baander\RedisStack\Fields\Bloom;

use Baander\RedisStack\Fields\Field;

class TopKField extends Field
{
    private string $operation; // Default is `TOPK.QUERY`
    private array $values;

    public function __construct(string $fieldName, array $values, string $operation = 'TOPK.QUERY')
    {
        parent::__construct($fieldName);
        $this->operation = $operation;
        $this->values = $values;
    }

    public function __toString(): string
    {
        return sprintf('%s %s %s', $this->operation, $this->fieldName, implode(' ', $this->values));
    }
}
