<?php

declare(strict_types=1);

namespace App\Metadata\Application\Port;

use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoTitle;

/** Provider failures are returned to the match queue. */
interface VideoMetadataPortInterface
{
    /** @return list<VideoProviderId> Providers in their default order for this kind of title. */
    public function providers(VideoKind $kind): array;

    /** @return list<VideoProviderId> Enabled providers with configured credentials. */
    public function usableProviders(VideoKind $kind): array;

    /** @return list<VideoCandidate>|VideoProviderProblem */
    public function search(VideoProviderId $provider, VideoRequest $request): array|VideoProviderProblem;

    public function identify(VideoProviderId $provider, VideoRequest $request): VideoTitle|VideoProviderProblem|null;
}
