<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Domain\Model\VideoMatchState;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoTitle;

interface VideoMatchApplyInterface
{
    /** Provider data is loaded before the guarded apply transaction. False means the claim or title changed. */
    public function apply(VideoMatchState $claim, VideoMatchTarget $target, VideoProviderId $provider, VideoTitle $metadata): bool;
}
