<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Dto\ResolvedVideoMatch;
use App\Catalog\Application\Message\RefreshVideoMatchMessage;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoRequest;

interface VideoMatchRefreshStoreInterface
{
    public function apply(
        RefreshVideoMatchMessage $message,
        VideoProviderId $provider,
        VideoRequest $request,
        ResolvedVideoMatch $match,
    ): bool;
}
