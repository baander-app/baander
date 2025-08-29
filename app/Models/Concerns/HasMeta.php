<?php

namespace App\Models\Concerns;

use App\Models\Enums\MetaKey;

trait HasMeta
{
    /**
     * Dynamic meta properties that can be serialized
     */
    protected array $meta = [];

    /**
     * Set a meta property with type safety
     *
     * @template T
     * @param MetaKey|string $key The meta property key
     * @param T $value The meta property value
     * @return self
     */
    public function setMeta(MetaKey|string $key, mixed $value): self
    {
        $keyString = $key instanceof MetaKey ? $key->value : $key;
        $this->meta[$keyString] = $value;
        return $this;
    }

    /**
     * Get a typed meta property
     *
     * @template T
     * @param MetaKey|string $key The meta property key
     * @param T|null $default Default value if key doesn't exist
     * @return T|null
     */
    public function getMeta(MetaKey|string $key, mixed $default = null): mixed
    {
        $keyString = $key instanceof MetaKey ? $key->value : $key;
        return $this->meta[$keyString] ?? $default;
    }

    /**
     * Get a meta property with type assertion
     *
     * @template T
     * @param MetaKey|string $key The meta property key
     * @param class-string<T> $type Expected type class
     * @param T|null $default Default value if key doesn't exist
     * @return T|null
     */
    public function getMetaAsType(MetaKey|string $key, string $type, mixed $default = null): mixed
    {
        $value = $this->getMeta($key, $default);

        if ($value === null) {
            return $default;
        }

        if (!$value instanceof $type) {
            return $default;
        }

        return $value;
    }

    /**
     * Get all meta properties
     *
     * @return array
     */
    public function getAllMeta(): array
    {
        return $this->meta;
    }

    /**
     * Set multiple meta properties at once
     *
     * @param array<MetaKey|string, mixed> $meta Associative array of key-value pairs
     * @return self
     */
    public function setMultipleMeta(array $meta): self
    {
        foreach ($meta as $key => $value) {
            $this->setMeta($key, $value);
        }
        return $this;
    }

    /**
     * Check if a meta property exists
     *
     * @param MetaKey|string $key The meta property key
     * @return bool
     */
    public function hasMeta(MetaKey|string $key): bool
    {
        $keyString = $key instanceof MetaKey ? $key->value : $key;
        return array_key_exists($keyString, $this->meta);
    }

    /**
     * Remove a meta property
     *
     * @param MetaKey|string $key The meta property key to remove
     * @return self
     */
    public function removeMeta(MetaKey|string $key): self
    {
        $keyString = $key instanceof MetaKey ? $key->value : $key;
        unset($this->meta[$keyString]);
        return $this;
    }

    /**
     * Clear all meta properties
     *
     * @return self
     */
    public function clearMeta(): self
    {
        $this->meta = [];
        return $this;
    }

    /**
     * Check if any meta properties exist
     *
     * @return bool
     */
    public function hasAnyMeta(): bool
    {
        return !empty($this->meta);
    }

    /**
     * Get meta property names (keys)
     *
     * @return array
     */
    public function getMetaKeys(): array
    {
        return array_keys($this->meta);
    }

    /**
     * Convert the model instance to an array including meta properties
     *
     * @return array
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        if ($this->hasAnyMeta()) {
            $array['meta'] = $this->meta;
        }

        return $array;
    }
}
