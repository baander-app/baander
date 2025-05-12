<?php

namespace Baander\RedisStack\Fields\Traits;

trait NoIndex
{
    protected bool $noindex = false;

    public function isNoindex(): bool
    {
        return $this->noindex;
    }

    public function setNoindex(bool $noindex)
    {
        $this->noindex = $noindex;
        return $this;
    }
}