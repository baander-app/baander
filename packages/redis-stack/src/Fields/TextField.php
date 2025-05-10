<?php

namespace Baander\RedisStack\Fields;

use Baander\RedisStack\Fields\Traits\NoIndex;
use Baander\RedisStack\Fields\Traits\Sortable;

class TextField extends Field
{
    use Sortable, NoIndex;

    private bool $nostem = false;
    private float $weight = 1.0;

    public function setNostem(bool $nostem): self
    {
        $this->nostem = $nostem;
        return $this;
    }

    public function getNostem(): bool
    {
        return $this->nostem;
    }

    public function setWeight(float $weight): self
    {
        $this->weight = $weight;
        return $this;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function setSortable(bool $sortable): self
    {
        $this->sortable = $sortable;
        return $this;
    }

    public function getSortable(): bool
    {
        return $this->sortable;
    }

    public function setNoindex(bool $noindex): self
    {
        $this->noindex = $noindex;
        return $this;
    }

    public function getNoindex(): bool
    {
        return $this->noindex;
    }

    public function __toString(): string
    {
        $options = [];

        if ($this->nostem) {
            $options[] = 'NOSTEM';
        }

        if ($this->weight !== 1.0) {
            $options[] = 'WEIGHT';
            $options[] = $this->weight;
        }

        if ($this->sortable) {
            $options[] = 'SORTABLE';
        }

        if ($this->noindex) {
            $options[] = 'NOINDEX';
        }

        return sprintf('@%s:%s', $this->fieldName, implode(' ', $options));
    }
}