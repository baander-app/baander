<?php

namespace Baander\RedisStack\Fields\Traits;

trait Sortable
{
    protected bool $sortable = false;

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function setSortable(bool $sortable)
    {
        $this->sortable = $sortable;
        return $this;
    }
}