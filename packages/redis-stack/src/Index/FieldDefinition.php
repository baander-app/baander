<?php

namespace Baander\RedisStack\Index;

class FieldDefinition
{
    private bool $noindex = false;
    private bool $sortable = false;
    private float $weight = 1.0;
    private ?int $maxLength = null;

    public function __construct(
        private string $name,
        private string $type,
    ) {
        $this->type = strtoupper($type);
    }

    public function setNoindex(bool $noindex): self
    {
        $this->noindex = $noindex;
        return $this;
    }

    public function setSortable(bool $sortable): self
    {
        $this->sortable = $sortable;
        return $this;
    }

    public function setWeight(float $weight): self
    {
        $this->weight = $weight;
        return $this;
    }

    public function setMaxLength(int $length): self
    {
        $this->maxLength = $length;
        return $this;
    }

    public function set()
    {

    }

    public function getSchema(): array
    {
        $schema = [$this->name, $this->type];

        if ($this->type === 'TEXT') {
            $schema[] = 'WEIGHT';
            $schema[] = $this->weight;
        }

        if ($this->sortable) {
            $schema[] = 'SORTABLE';
        }

        if ($this->noindex) {
            $schema[] = 'NOINDEX';
        }

        if ($this->maxLength !== null) {
            $schema[] = 'MAXLENGTH';
            $schema[] = $this->maxLength;
        }

        return $schema;
    }
}