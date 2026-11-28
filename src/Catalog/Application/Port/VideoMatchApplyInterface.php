<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Application\Dto\ResolvedVideoMatch;
use App\Catalog\Application\Dto\PreparedVideoMatchMetadata;
use App\Catalog\Domain\Model\VideoMatchState;
use App\Metadata\Application\DTO\Video\VideoProviderId;

interface VideoMatchApplyInterface
{
    /** Provider data is loaded before the guarded apply transaction. False means the claim or title changed. */
    public function apply(
        VideoMatchState $claim,
        VideoMatchTarget $target,
        VideoProviderId $provider,
        ResolvedVideoMatch $match,
        PreparedVideoMatchMetadata $prepared,
    ): bool;
}
