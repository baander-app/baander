<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Service;

use App\Shared\Application\Exception\UnknownSettingException;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\DTO\UserSettingEntry;
use App\UserPreference\Application\DTO\UserSettingSource;
use App\UserPreference\Application\Port\UserSettingStoreInterface;
use LogicException;

/**
 * Resolves a user's settings from their explicit choices and the defaults.
 * A setting that follows a system setting takes that setting's current value
 * as its default. Both stores are read afresh on every call.
 */
final readonly class UserSettingsReader
{
    public function __construct(
        private SettingDefinitionRegistry $definitions,
        private UserSettingStoreInterface $store,
        private SystemSettingsPortInterface $systemSettings,
    ) {
    }

    /**
     * @return list<UserSettingEntry>
     */
    public function entries(Uuid $userId): array
    {
        $stored = $this->store->findAll($userId);

        return array_map(
            fn (SettingDefinition $definition): UserSettingEntry => $this->build($definition, $stored[$definition->key] ?? null),
            $this->definitions->forScope(SettingScope::User),
        );
    }

    /**
     * @throws UnknownSettingException when no user setting has the key
     */
    public function entry(Uuid $userId, string $key): UserSettingEntry
    {
        return $this->build($this->definition($key), $this->store->find($userId, $key));
    }

    /**
     * @throws UnknownSettingException when no user setting has the key
     */
    public function definition(string $key): SettingDefinition
    {
        return $this->definitions->require($key, SettingScope::User);
    }

    private function build(SettingDefinition $definition, mixed $storedValue): UserSettingEntry
    {
        $choice = $definition->valueOrNull($storedValue);
        $resetValue = $definition->fallbackKey !== null
            ? $this->systemSettings->get($definition->fallbackKey)
            : $definition->default ?? throw new LogicException(sprintf('User setting "%s" has no default.', $definition->key));

        return new UserSettingEntry(
            definition: $definition,
            storedValue: $storedValue,
            choice: $choice,
            value: $choice ?? $resetValue,
            resetValue: $resetValue,
            source: match (true) {
                $choice !== null => UserSettingSource::User,
                $definition->fallbackKey !== null => UserSettingSource::ServerDefault,
                default => UserSettingSource::Default,
            },
        );
    }
}
