<?php

declare(strict_types=1);

namespace App\Radio\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface RadioSessionPortInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function getSession(Uuid $userId): ?array;

    /**
     * @return array<string, mixed>
     */
    public function startRadio(Uuid $userId, Uuid $stationId, string $streamUrl): array;

    /**
     * @return array<string, mixed>
     */
    public function stopRadio(Uuid $userId): array;
}
