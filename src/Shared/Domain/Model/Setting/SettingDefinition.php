<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model\Setting;

use InvalidArgumentException;

/**
 * One server-wide or per-user setting: its value contract, default, who may
 * edit it, and the labels the settings pages render.
 *
 * A user-scope setting may follow a system setting instead of carrying its
 * own default; its default is then that system setting's current value.
 */
final readonly class SettingDefinition
{
    public const string ROLE_USER = 'ROLE_USER';
    public const string ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    /**
     * @param list<int|string>      $allowedValues enum values, all strings or all integers
     * @param array<int|string, string> $valueLabels display label per allowed value (PHP turns numeric keys into integers)
     */
    public function __construct(
        public string $key,
        public SettingValueType $type,
        public SettingScope $scope,
        public string $label,
        public string $description,
        public string $group,
        public bool|int|string|null $default = null,
        public array $allowedValues = [],
        public array $valueLabels = [],
        public ?int $min = null,
        public ?int $max = null,
        public string $editRole = self::ROLE_SUPER_ADMIN,
        public bool $userVisible = false,
        public bool $enforced = true,
        public ?string $fallbackKey = null,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Setting key "%s" must be dot-separated lowercase words.', $key));
        }

        $this->assertValueContract();
        $this->assertDefault();

        if ($userVisible && $scope !== SettingScope::System) {
            throw new InvalidArgumentException(sprintf('Setting "%s": only system settings can be user-visible.', $key));
        }

        foreach (array_keys($valueLabels) as $value) {
            if (!in_array((string) $value, array_map(strval(...), $allowedValues), true)) {
                throw new InvalidArgumentException(sprintf('Setting "%s" labels "%s", which is not an allowed value.', $key, $value));
            }
        }
    }

    /**
     * Whether an already-typed value satisfies this definition.
     */
    public function allows(mixed $value): bool
    {
        return match ($this->type) {
            SettingValueType::Boolean => is_bool($value),
            SettingValueType::Integer => is_int($value)
                && ($this->min === null || $value >= $this->min)
                && ($this->max === null || $value <= $this->max),
            SettingValueType::Enum => in_array($value, $this->allowedValues, true),
            SettingValueType::String => is_string($value),
        };
    }

    public function isUserEditable(): bool
    {
        return $this->scope === SettingScope::User && $this->editRole === self::ROLE_USER;
    }

    private function assertValueContract(): void
    {
        if ($this->type === SettingValueType::Enum) {
            if ($this->allowedValues === []) {
                throw new InvalidArgumentException(sprintf('Enum setting "%s" needs allowed values.', $this->key));
            }

            $integers = array_filter($this->allowedValues, is_int(...));
            if ($integers !== [] && count($integers) !== count($this->allowedValues)) {
                throw new InvalidArgumentException(sprintf('Enum setting "%s" mixes integer and string values.', $this->key));
            }
        } elseif ($this->allowedValues !== []) {
            throw new InvalidArgumentException(sprintf('Only enum settings have allowed values; "%s" is %s.', $this->key, $this->type->value));
        }

        if (($this->min !== null || $this->max !== null) && $this->type !== SettingValueType::Integer) {
            throw new InvalidArgumentException(sprintf('Only integer settings have bounds; "%s" is %s.', $this->key, $this->type->value));
        }

        if ($this->min !== null && $this->max !== null && $this->min > $this->max) {
            throw new InvalidArgumentException(sprintf('Setting "%s" has a minimum above its maximum.', $this->key));
        }
    }

    private function assertDefault(): void
    {
        if ($this->fallbackKey !== null) {
            if ($this->scope !== SettingScope::User) {
                throw new InvalidArgumentException(sprintf('Only user settings can follow a system setting; "%s" is a system setting.', $this->key));
            }
            if ($this->default !== null) {
                throw new InvalidArgumentException(sprintf('Setting "%s" follows "%s" and cannot also have a default.', $this->key, $this->fallbackKey));
            }

            return;
        }

        if ($this->default === null || !$this->allows($this->default)) {
            throw new InvalidArgumentException(sprintf('Setting "%s" needs a default that is one of its allowed values.', $this->key));
        }
    }
}
