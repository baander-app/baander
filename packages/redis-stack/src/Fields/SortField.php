<?php

namespace Baander\RedisStack\Fields;

class SortField extends Field
{
    private string $order;

    public function __construct(string $fieldName, string $order = 'ASC')
    {
        parent::__construct($fieldName);
        $this->order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
    }

    public function __toString(): string
    {
        return sprintf('SORTBY %s %s', $this->fieldName, $this->order);
    }
}