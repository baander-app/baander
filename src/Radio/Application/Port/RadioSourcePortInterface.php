<?php

declare(strict_types=1);

namespace App\Radio\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Radio\Domain\Model\RadioSource\RadioSource;
use App\Shared\Domain\Model\Uuid;

interface RadioSourcePortInterface
{
    public function findByPublicId(PublicId $publicId): ?RadioSource;

    /**
     * Every source. Only an administrator's view carries the sync URL and configuration,
     * which can hold an operator's endpoint or credentials.
     *
     * @return list<array<string, mixed>>
     */
    public function listSources(bool $administrator): array;

    /**
     * The created source in the administrator's view.
     *
     * @return array<string, mixed>
     * @param array<string, mixed> $syncConfig
     */
    public function createSource(string $name, string $type, string $syncUrl, array $syncConfig, ?string $syncSchedule = null): array;
}
