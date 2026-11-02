<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

final class WorkerStateStore implements \Baander\Tooling\Deployment\WorkerStateStore
{
    /** @param array<string,mixed> $instance */
    public function __construct(private readonly StateStore $store, private array $instance) {}

    public function directory(): string
    {
        return $this->store->directory($this->instance['id']) . '/workers';
    }

    /** @param array<string,mixed> $instance
     * @return array<string,mixed>|null
     */
    public static function hydrate(array $instance): ?array
    {
        if (empty($instance['workers'])) {
            return null;
        }
        return $instance['workers'] + [
            'namespace' => $instance['project'],
            'network' => Runtime::networkName($instance),
            'target' => $instance['docker'],
        ];
    }

    public function load(): ?array
    {
        $saved = $this->store->load($this->instance['id']);
        return $saved === null ? null : self::hydrate($saved);
    }

    public function save(array $record): void
    {
        $this->instance['workers'] = $record;
        $this->store->save($this->instance['id'], $this->instance);
    }
}
