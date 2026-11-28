<?php

declare(strict_types=1);

namespace App\Activity\Application\DTO;

use App\Shared\Domain\Model\Uuid;

final readonly class ViewingState
{
    /**
     * @param list<Uuid> $watchedEpisodeIds
     * @param list<Uuid> $watchedMovieIds
     * @param array<string, VideoResumeState> $resumes Exact file state, including cleared positions.
     */
    public function __construct(
        public array $watchedEpisodeIds,
        public array $watchedMovieIds,
        public array $resumes,
    ) {}
}
