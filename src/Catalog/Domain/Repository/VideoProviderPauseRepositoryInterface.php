<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\VideoProviderPause;

interface VideoProviderPauseRepositoryInterface
{
    public function find(string $provider): ?VideoProviderPause;

    public function pause(string $provider, string $failureKind): VideoProviderPause;

    public function clear(string $provider): void;

    /** @return list<VideoProviderPause> */
    public function activePauses(): array;
}
