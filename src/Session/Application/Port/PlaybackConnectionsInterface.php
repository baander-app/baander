<?php

declare(strict_types=1);

namespace App\Session\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface PlaybackConnectionsInterface
{
    public function bind(int $fd, Uuid $owner, ?string $incarnation = null, ?string $capability = null): void;
    public function unbind(int $fd): void;
}
