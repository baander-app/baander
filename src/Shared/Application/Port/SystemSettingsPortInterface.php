<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface SystemSettingsPortInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    /**
     * @return array<string, mixed>
     */
    public function all(): array;

    public function remove(string $key): void;
}
