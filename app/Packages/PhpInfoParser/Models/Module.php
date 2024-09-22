<?php

namespace App\Packages\PhpInfoParser\Models;

use Illuminate\Support\{Collection, Str};
use JsonSerializable;

class Module implements JsonSerializable
{
    public function __construct(protected string $name, protected Collection $groups)
    {
    }

    public function key(): string
    {
        return 'module_' . Str::slug($this->name);
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
                return $config->key() === 'config_' . Str::slug($key);
            }) !== null;
    }

    public function config($key, $which = "local"): string|null
    {
        return $this->configs()->first(function (Config $config) use ($key) {
            return $config->key() === 'config_' . Str::slug($key);
        })?->value($which);
    }

    public function configs(): Collection
    {
        return $this->groups()->flatMap->configs();
    }

    public function jsonSerialize(): mixed
    {
        return [
            'key'    => $this->key(),
            'name'   => $this->name(),
            'groups' => $this->groups()->values(),
        ];
    }
}