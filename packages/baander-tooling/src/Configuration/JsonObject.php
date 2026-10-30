<?php

declare(strict_types=1);

namespace Baander\Tooling\Configuration;

final class JsonObject
{
    /** @return array<string,mixed> */
    public static function read(string $path): array
    {
        return self::convert(self::object($path));
    }

    public static function object(string $path): \stdClass
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException('Cannot read configuration: ' . $path);
        }
        try {
            $object = json_decode($contents, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('Invalid JSON in ' . $path, previous: $error);
        }
        if (!$object instanceof \stdClass) {
            throw new \InvalidArgumentException('Configuration must be a JSON object: ' . $path);
        }
        return $object;
    }

    /** @return array<string,mixed> */
    private static function convert(\stdClass $object): array
    {
        $result = [];
        foreach (get_object_vars($object) as $key => $value) {
            $result[$key] = $value instanceof \stdClass ? self::convert($value) : $value;
        }
        return $result;
    }

    /** @param array<array-key,mixed> $data
     * @param list<string> $keys
     */
    public static function keys(array $data, array $keys, string $description): void
    {
        if (array_diff(array_keys($data), $keys) !== []) {
            throw new \InvalidArgumentException('Unknown key in ' . $description . '.');
        }
    }
}
