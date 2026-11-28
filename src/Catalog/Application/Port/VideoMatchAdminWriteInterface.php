<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Query\VideoMatch\VideoMatchTargetIdentity;
use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Shared\Domain\Model\Uuid;

interface VideoMatchAdminWriteInterface
{
    /** False means the title changed while provider data was loaded. */
    public function rematch(VideoMatchTargetIdentity $identity, VideoMatchTarget $target, VideoProviderId $provider, VideoTitle $metadata, ?VideoTitle $aired = null): bool;

    public function setOrdering(VideoMatchTargetIdentity $identity, VideoMatchTarget $target, string $ordering, VideoProviderId $provider, VideoTitle $metadata, ?VideoTitle $aired = null): bool;

    /** @param list<Uuid> $episodeIds */
    public function assignFile(VideoPlacement $placement, array $episodeIds, ?ExtraKind $extra): bool;

    /** Queues an eligible title while holding its identity lock. */
    public function requestLibraryTitle(VideoMatchTargetIdentity $target, bool $force): bool;
}
