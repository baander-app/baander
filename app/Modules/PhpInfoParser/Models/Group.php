<?php

namespace App\Modules\PhpInfoParser\Models;

use Illuminate\Support\{Collection, Str};
use JsonSerializable;

class Group implements JsonSerializable
{
    public function __construct(protected Collection $configs, protected ?Collection $headings = null, protected $name = null, protected $note = null)
    {
    }

    public static function simple($name, $configName, $contents): static
    {
        return new static(collect([new Config($configName, $contents)]), null, $name);
    }

    public static function noteOnly($note): Group
    {
        return new static(collect())->addNote($note);
    }

    public function addNote($note): self
    {
        $this->note = $note;

        return $this;
    }

    public function key(): string
    {
        return $this->name()
            ? 'group_' . Str::slug($this->name())
            : 'group_' . md5($this->configs()->map->name()
                ->implode(','));
    }

    public function name(): string|null
    {
        return $this->name;
    }

    public function note(): string|null
    {
        return $this->note;
    }

    public function configs(): Collection
    {
        return $this->configs;
    }

    public function hasHeadings(): bool
    {
        return $this->headings?->count() > 0;
    }

    public function headings(): Collection
    {
        return $this->hasHeadings() ? $this->headings : collect();
    }

    public function heading($index): string|null
    {
        return $this->headings->get($index);
    }

    public function shortHeading($index): string|null
    {
        return $this->shorten($this->headings->get($index));
    }

    protected function shorten($heading): string|null
    {
        return trim(str_replace('Value', '', $heading));
    }

    public function jsonSerialize(): array
    {
        return [
            'key'           => $this->key(),
            'name'          => $this->name(),
            'headings'      => $this->headings(),
            'shortHeadings' => $this->headings()->map(fn($heading) => $this->shorten($heading)),
            'configs'       => $this->configs()->values(),
            'note'          => $this->note(),
        ];
    }
}