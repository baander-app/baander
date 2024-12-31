<?php

namespace App\Packages\PhpInfoParser;

use InvalidArgumentException;
use JsonSerializable;
use App\Packages\PhpInfoParser\Concerns\ConfigAliases;
use App\Packages\PhpInfoParser\Models\{Config, Module};
use Illuminate\Support\{Collection, Str};

abstract class Result implements JsonSerializable
{
    use ConfigAliases;

    protected string $version;
    protected Collection $modules;
    protected Collection $configs;

    public function __construct(protected string $contents)
    {
        if (!static::canParse($contents)) {
            throw new InvalidArgumentException('Content provided does not appear to be valid phpinfo() output');
        }

        $this->parse();
    }

    abstract public static function canParse(string $contents): bool;

    abstract protected function parse(): void;

    public function version(): string
    {
        return $this->version;
    }

    public function module($key): Module|null
    {
        return $this->modules()->first(function (Module $module) use ($key) {
            return $module->key() === 'module_' . Str::slug($key);
        });
    }

    public function hasModule($key): bool
    {
        return !!$this->module($key);
    }

    public function modules(): Collection
    {
        return $this->modules;
    }

    public function hasConfig($key): bool
    {
        return $this->configs()->first(function ($config) use ($key) {
                return $config->key() === 'config_' . Str::slug($key);
            }) !== null;
    }

    public function config($key, $which = 'local'): string|null
    {
        if (in_array($key, $this->aliases)) {
            $aliasMethod = 'get' . ucfirst($key);

            return $this->$aliasMethod();
        }

        return $this->configs()->first(function (Config $config) use ($key) {
            return $config->key() === 'config_' . Str::slug($key);
        })?->value($which);
    }

    public function configs(): Collection
    {
        if (!isset($this->configs)) {
            $this->configs = $this->modules()->flatMap->configs();
        }

        return $this->configs;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'version' => $this->version(),
            'modules' => $this->modules()->values(),
        ];
    }
}