<?php

declare(strict_types=1);

namespace App\Playlist\Domain\Model;

use InvalidArgumentException;

final class SmartPlaylist
{
    /**
     * Valid operators for smart playlist rules.
     */
    private const array VALID_OPERATORS = [
        'equals',
        'not_equals',
        'contains',
        'greater_than',
        'less_than',
        'is_empty',
        'is_not_empty',
    ];

    /**
     * Required keys for a rule.
     */
    private const array REQUIRED_KEYS = ['field', 'operator'];

    /**
     * Validate and parse smart playlist rules from JSON input.
     *
     * Each rule must have at least "field" and "operator" keys.
     * Operators that require a value: equals, not_equals, contains, greater_than, less_than.
     * Operators that do not require a value: is_empty, is_not_empty.
     *
     * @param array<int, array<string, mixed>> $json
     *
     * @return array<int, array{field: string, operator: string, value?: mixed}>
     */
    public static function parseRules(array $json): array
    {
        if ($json === []) {
            return [];
        }

        $rules = [];
        foreach ($json as $index => $rule) {
            if (!is_array($rule)) {
                throw new InvalidArgumentException(
                    sprintf('Rule at index %d must be an object.', $index),
                );
            }

            foreach (self::REQUIRED_KEYS as $key) {
                if (!array_key_exists($key, $rule)) {
                    throw new InvalidArgumentException(
                        sprintf('Rule at index %d is missing required key "%s".', $index, $key),
                    );
                }
            }

            $field = $rule['field'];
            $operator = $rule['operator'];

            if (!is_string($field) || trim($field) === '') {
                throw new InvalidArgumentException(
                    sprintf('Rule at index %d must have a non-empty string "field".', $index),
                );
            }

            if (!is_string($operator) || !in_array($operator, self::VALID_OPERATORS, true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Rule at index %d has invalid operator "%s". Valid operators: %s',
                        $index,
                        $operator,
                        implode(', ', self::VALID_OPERATORS),
                    ),
                );
            }

            $parsed = [
                'field' => $field,
                'operator' => $operator,
            ];

            // Operators that require a value
            if (in_array($operator, ['equals', 'not_equals', 'contains', 'greater_than', 'less_than'], true)) {
                if (!array_key_exists('value', $rule)) {
                    throw new InvalidArgumentException(
                        sprintf('Rule at index %d requires a "value" key for operator "%s".', $index, $operator),
                    );
                }
                $parsed['value'] = $rule['value'];
            }

            $rules[] = $parsed;
        }

        return $rules;
    }

    /**
     * Get the list of valid operators.
     *
     * @return string[]
     */
    public static function getValidOperators(): array
    {
        return self::VALID_OPERATORS;
    }
}
