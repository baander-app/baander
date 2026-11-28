<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Video;

use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoTitle;

interface VideoProviderAdapter
{
    public function provider(): VideoProviderId;

    public function isConfigured(): bool;

    /** @return list<VideoCandidate>|VideoProviderProblem */
    public function search(VideoRequest $request): array|VideoProviderProblem;

    public function identify(VideoRequest $request): VideoTitle|VideoProviderProblem|null;
}
