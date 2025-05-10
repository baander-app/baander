<?php

namespace Baander\RedisStack\Query;

class Reducer
{
    private string $function;
    private string $field;
    private ?string $alias = null;

    public function __construct(string $function, string $field)
    {
        $this->function = strtoupper($function);
        $this->field = $field;
    }

    public function as(string $alias): self
    {
        $this->alias = $alias;
        return $this;
    }

    public function build(): array
    {
        $reducer = ['REDUCE', $this->function, '1', $this->field];
        if ($this->alias) {
            $reducer[] = 'AS';
            $reducer[] = $this->alias;
        }
        return $reducer;
    }
}