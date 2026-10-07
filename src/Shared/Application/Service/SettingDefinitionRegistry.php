<?php

declare(strict_types=1);

namespace App\Shared\Application\Service;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use LogicException;

/**
 * Every setting definition contributed by the contexts, keyed by setting key.
 * The definitions are collected and checked on first use.
 */
final class SettingDefinitionRegistry
{
    /** @var array<string, SettingDefinition>|null */
    private ?array $definitions = null;

    /**
     * @param iterable<SettingDefinitionProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {
    }

    public function get(string $key): ?SettingDefinition
    {
        return $this->definitions()[$key] ?? null;
    }

    /**
     * @return list<SettingDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions());
    }

    /**
     * @return list<SettingDefinition>
     */
    public function forScope(SettingScope $scope): array
    {
        return array_values(array_filter(
            $this->definitions(),
            static fn (SettingDefinition $definition): bool => $definition->scope === $scope,
        ));
    }

    /**
     * @return array<string, SettingDefinition>
     */
    private function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->definitions() as $definition) {
                if (isset($definitions[$definition->key])) {
                    throw new LogicException(sprintf('Setting "%s" is defined more than once.', $definition->key));
                }
                $definitions[$definition->key] = $definition;
            }
        }

        foreach ($definitions as $definition) {
            if ($definition->fallbackKey === null) {
                continue;
            }

            $fallback = $definitions[$definition->fallbackKey] ?? null;
            if ($fallback === null || $fallback->scope !== SettingScope::System) {
                throw new LogicException(sprintf(
                    'Setting "%s" follows "%s", which is not a system setting.',
                    $definition->key,
                    $definition->fallbackKey,
                ));
            }
            if ($fallback->type !== $definition->type) {
                throw new LogicException(sprintf(
                    'Setting "%s" follows "%s", which has a different value type.',
                    $definition->key,
                    $definition->fallbackKey,
                ));
            }
        }

        return $this->definitions = $definitions;
    }
}
