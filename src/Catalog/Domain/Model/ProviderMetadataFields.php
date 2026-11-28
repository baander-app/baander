<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use InvalidArgumentException;

final class ProviderMetadataFields
{
    public static function text(string|int|null $value): ?string
    {
        if (is_int($value)) {
            throw new InvalidArgumentException('Provider text metadata must be a string.');
        }

        return $value;
    }

    public static function number(string|int|null $value): ?int
    {
        if (is_string($value)) {
            throw new InvalidArgumentException('Provider numeric metadata must be an integer.');
        }

        return $value;
    }

    /**
     * @param array<string, string|int|null> $current
     * @param array<string, string|int|null> $providerFields
     * @param array<string, string|int|null> $incoming
     * @param list<string> $locked
     * @return array{values: array<string, string|int|null>, providerFields: array<string, string|int|null>}
     */
    public static function merge(array $current, array $providerFields, array $incoming, array $locked, bool $force): array
    {
        $structureOnlyFields = ['airDate' => true, 'absoluteNumber' => true];
        $isFirstMetadataApply = array_diff_key($providerFields, $structureOnlyFields) === [];
        foreach ($incoming as $field => $value) {
            if (!array_key_exists($field, $current) || $value === null || in_array($field, $locked, true)) {
                continue;
            }
            $providerOwned = array_key_exists($field, $providerFields) && $current[$field] === $providerFields[$field];
            if ($force || $isFirstMetadataApply || $current[$field] === null || $current[$field] === '' || $providerOwned) {
                $current[$field] = $value;
                $providerFields[$field] = $value;
            }
        }

        return ['values' => $current, 'providerFields' => $providerFields];
    }
}
