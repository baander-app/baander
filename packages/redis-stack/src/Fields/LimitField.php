<?php

namespace Baander\RedisStack\Fields;

class LimitField extends Field
{
    private int $offset;
    private int $pageSize;

    public function __construct(int $offset, int $pageSize)
    {
        parent::__construct('LIMIT'); // Not really a "field," but shared base is okay here.
        $this->offset = $offset;
        $this->pageSize = $pageSize;
    }

    public function __toString(): string
    {
        return sprintf('%s %d %d', $this->fieldName, $this->offset, $this->pageSize);
    }
}