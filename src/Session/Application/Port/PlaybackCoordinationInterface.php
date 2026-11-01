<?php

declare(strict_types=1);

namespace App\Session\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface PlaybackCoordinationInterface
{
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function execute(Uuid $userId, PlaybackOperation $operation, array $input, ?string $endpointCapability = null): array;
}
