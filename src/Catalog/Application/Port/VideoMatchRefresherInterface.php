<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Message\RefreshVideoMatchMessage;

interface VideoMatchRefresherInterface
{
    public function refresh(RefreshVideoMatchMessage $message): void;
}
