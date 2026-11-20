<?php

declare(strict_types=1);

namespace Baander\Core\Configuration;

use BackedEnum;
use InvalidArgumentException;

final readonly class ConfigPath
{
    /** @param list<string|int> $segments */
    private function __construct(private array $segments) {}

    public static function root(): self
    {
        return new self([]);
    }

    public function key(string|BackedEnum $key): self
    {
        $name = $key instanceof BackedEnum ? $key->value : $key;
        if (!is_string($name)) {
            throw new InvalidArgumentException('Configuration keys require strings or string-backed enums.');
        }
        return new self([...$this->segments, $name]);
    }

    public function index(int $index): self
    {
        if ($index < 0) {
            throw new InvalidArgumentException('Configuration indexes must be nonnegative.');
        }
        return new self([...$this->segments, $index]);
    }

    /** @param array<array-key,mixed> $configuration */
    public function value(array $configuration): mixed
    {
        $value = $configuration;
        $path = '$';
        foreach ($this->segments as $segment) {
            $path .= self::segmentLabel($segment);
            if (!is_array($value)) {
                throw new InvalidArgumentException('Configuration parent of ' . $path . ' must be an array; got ' . get_debug_type($value) . '.');
            }
            if (!array_key_exists($segment, $value)) {
                throw new InvalidArgumentException('Missing configuration key at ' . $path . '.');
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /** @param array<array-key,mixed> $configuration */
    public function integer(array $configuration): int
    {
        $value = $this->value($configuration);
        if (!is_int($value)) {
            throw new InvalidArgumentException('Configuration value at ' . $this->label() . ' must be an integer; got ' . get_debug_type($value) . '.');
        }
        return $value;
    }

    private function label(): string
    {
        return '$' . (array_map(self::segmentLabel(...), $this->segments) |> implode(...));
    }

    private static function segmentLabel(string|int $segment): string
    {
        return '[' . var_export($segment, true) . ']';
    }
}
