<?php

declare(strict_types=1);

namespace App\Radio\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface RadioSourcePortInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listSources(): array;

    /**
     * @return array<string, mixed>
     */
    public function createSource(string $name, string $type, string $syncUrl, array $syncConfig, ?string $syncSchedule = null): array;
}
