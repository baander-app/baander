<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use UnexpectedValueException;

/**
 * The JSON text a setting store keeps in its jsonb value column.
 */
final class JsonSettingValue
{
    private function __construct()
    {
    }

    public static function encode(bool|int|string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $subject what the value belongs to, such as "system setting", for the error message
     *
     * @throws UnexpectedValueException when the column was not read as text
     * @throws \JsonException           when the text is not valid JSON
     */
    public static function decode(mixed $json, string $subject): mixed
    {
        if (!is_string($json)) {
            throw new UnexpectedValueException(sprintf('A %s value must be read as JSON text.', $subject));
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
