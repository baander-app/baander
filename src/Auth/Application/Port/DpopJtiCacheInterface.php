<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

interface DpopJtiCacheInterface
{
    public function isReplay(string $jti): bool;

    public function store(string $jti, int $ttlSeconds): void;
}
