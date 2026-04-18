<?php

namespace App\Modules\PhpInfoParser\Models;

use App\Primitives\Text;
use Illuminate\Support\Collection;
use JsonSerializable;

class Module implements JsonSerializable
{
    public function __construct(protected string $name, protected Collection $groups)
    {
    }

    public function key(): string
    {
        return 'module_' . Text::slug($this->name)->value();
    }

    public function combinedKeyFor(Config $config): string
    {
        return $this->key() . '_' . $config->key();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function groups(): Collection
    {
        return $this->groups;
    }

    public function hasConfig($key): bool
    {
        return $this->configs()->first(function (Config $config) use ($key) {
                return $config->key() === 'config_' . Text::slug($key)->value();
            }) !== null;
    }

    public function config($key, $which = "local"): string|null
    {
        return $this->configs()->first(function (Config $config) use ($key) {
            return $config->key() === 'config_' . Text::slug($key);
        })?->value($which);
    }

    public function configs(): Collection
    {
        return $this->groups()->flatMap->configs();
    }

    public function jsonSerialize(): array
    {
        return [
            'key'    => $this->key(),
            'name'   => $this->name(),
            'groups' => $this->groups()->values(),
        ];
    }
}