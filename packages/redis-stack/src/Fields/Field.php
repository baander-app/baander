<?php

namespace Baander\RedisStack\Fields;

abstract class Field
{
    protected string $fieldName;

    public function __construct(string $fieldName)
    {
        $this->fieldName = $fieldName;
    }

    abstract public function __toString(): string;
}