<?php

declare(strict_types=1);

namespace App\Shared\Application\Service;

use App\Shared\Application\DTO\SystemSettingEntry;
use App\Shared\Application\Exception\UnknownSettingException;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use LogicException;

final readonly class SystemSettings implements SystemSettingsPortInterface
{
    public function __construct(
        private SettingDefinitionRegistry $definitions,
        private SystemSettingStoreInterface $store,
    ) {
    }

    public function get(string $key): bool|int|string
    {
        return $this->effective($this->definition($key), $this->store->find($key));
    }

    /**
     * Every server-wide setting with its effective and stored value.
     *
     * @return list<SystemSettingEntry>
     */
    public function entries(): array
    {
        $stored = $this->store->all();

        return array_map(
            fn (SettingDefinition $definition): SystemSettingEntry => $this->buildEntry($definition, $stored[$definition->key] ?? null),
            $this->definitions->forScope(SettingScope::System),
        );
    }

    /**
     * @throws UnknownSettingException when no system setting has the key
     */
    public function entry(string $key): SystemSettingEntry
    {
        return $this->buildEntry($this->definition($key), $this->store->find($key));
    }

    /**
     * @throws UnknownSettingException when no system setting has the key
     */
    public function definition(string $key): SettingDefinition
    {
        return $this->definitions->require($key, SettingScope::System);
    }

    private function buildEntry(SettingDefinition $definition, mixed $storedValue): SystemSettingEntry
    {
        return new SystemSettingEntry(
            definition: $definition,
            value: $this->effective($definition, $storedValue),
            storedValue: $storedValue,
            storedValueValid: $storedValue === null || $definition->valueOrNull($storedValue) !== null,
        );
    }

    private function effective(SettingDefinition $definition, mixed $storedValue): bool|int|string
    {
        // System definitions always carry a default; only user settings follow a fallback.
        return $definition->valueOrNull($storedValue)
            ?? $definition->default
            ?? throw new LogicException(sprintf('System setting "%s" has no default.', $definition->key));
    }
}
