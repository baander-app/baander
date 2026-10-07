<?php

declare(strict_types=1);

namespace App\Shared\Application\Service;

use App\Shared\Application\DTO\SettingParseResult;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingValueType;

/**
 * The only path from user input to a typed setting value. Accepts a CLI
 * string or a decoded JSON value, so the API and the CLI cannot drift.
 */
final class SettingValueParser
{
    public function parse(SettingDefinition $definition, mixed $input): SettingParseResult
    {
        $value = match ($definition->type) {
            SettingValueType::Boolean => $this->toBoolean($input),
            SettingValueType::Integer => $this->toInteger($input),
            SettingValueType::Enum => $this->toEnumMember($definition, $input),
            SettingValueType::String => is_string($input) ? $input : null,
        };

        if ($value === null || !$definition->allows($value)) {
            return SettingParseResult::invalid($definition->key, $this->describe($definition));
        }

        return SettingParseResult::valid($value);
    }

    private function toBoolean(mixed $input): ?bool
    {
        return match ($input) {
            true, 'true' => true,
            false, 'false' => false,
            default => null,
        };
    }

    private function toInteger(mixed $input): ?int
    {
        if (is_int($input)) {
            return $input;
        }

        if (is_string($input) && preg_match('/^-?\d+$/', $input) === 1) {
            return (int) $input;
        }

        return null;
    }

    private function toEnumMember(SettingDefinition $definition, mixed $input): int|string|null
    {
        if (is_int($definition->allowedValues[0] ?? null)) {
            return $this->toInteger($input);
        }

        return is_string($input) ? $input : null;
    }

    private function describe(SettingDefinition $definition): string
    {
        return match ($definition->type) {
            SettingValueType::Boolean => 'Must be true or false.',
            SettingValueType::Integer => match (true) {
                $definition->min !== null && $definition->max !== null => sprintf('Must be a whole number from %d to %d.', $definition->min, $definition->max),
                $definition->min !== null => sprintf('Must be a whole number of at least %d.', $definition->min),
                $definition->max !== null => sprintf('Must be a whole number of at most %d.', $definition->max),
                default => 'Must be a whole number.',
            },
            SettingValueType::Enum => sprintf('Must be one of: %s.', implode(', ', $definition->allowedValues)),
            SettingValueType::String => 'Must be text.',
        };
    }
}
