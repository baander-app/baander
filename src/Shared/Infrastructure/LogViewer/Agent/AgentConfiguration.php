<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Agent;

final readonly class AgentConfiguration
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values)
    {
        foreach (['nodeId', 'databasePath', 'tokenFile', 'checkpointTokenFile'] as $key) {
            if (!is_string($values[$key] ?? null) || '' === $values[$key]) {
                throw new \InvalidArgumentException('Missing agent configuration: '.$key);
            }
        }
        if (isset($values['dockerSocket']) && (empty($values['expectedDaemonId']) || empty($values['containerGroups']))) {
            throw new \InvalidArgumentException('Docker collection requires daemon identity and explicit ownership and service admission.');
        }
        if (($values['retentionDays'] ?? 7) < 1 || ($values['maxStorageBytes'] ?? 1073741824) < 1048576) {
            throw new \InvalidArgumentException('Invalid retention bounds.');
        }
    }

    public static function load(string $path): self
    {
        $json = str_starts_with(ltrim($path), '{') ? $path : file_get_contents($path);
        if (false === $json) {
            throw new \RuntimeException('Cannot read agent configuration.');
        }

        return new self(json_decode($json, true, 32, JSON_THROW_ON_ERROR));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /** @param array<string, mixed> $container */
    public function admits(array $container): bool
    {
        $labels = $container['Config']['Labels'] ?? $container['Labels'] ?? [];
        if ('true' === ($labels['io.baander.log-agent'] ?? null) || in_array($container['Id'] ?? '', $this->get('excludedContainerIds', []), true)) {
            return false;
        }
        return null !== $this->group($container);
    }

    /** @param array<string, mixed> $container
     * @return array<string, mixed>|null
     */
    public function group(array $container): ?array
    {
        $labels = $container['Config']['Labels'] ?? $container['Labels'] ?? [];
        foreach ($this->get('containerGroups', []) as $group) {
            $matches = !empty($group['labels']);
            foreach ($group['labels'] ?? [] as $key => $value) {
                $matches = $matches && ($labels[$key] ?? null) === $value;
            }
            $service = $labels[$group['serviceLabel'] ?? 'com.docker.compose.service'] ?? '';
            if ($matches && (!isset($group['services']) || in_array($service, $group['services'], true))) {
                return $group;
            }
        }

        return null;
    }

    public function token(bool $checkpoint = false): string
    {
        $path = $this->get($checkpoint ? 'checkpointTokenFile' : 'tokenFile');
        $token = trim((string) file_get_contents($path));
        if (strlen($token) < 32) {
            throw new \RuntimeException('Agent credential must contain at least 32 characters.');
        }

        return $token;
    }
}
