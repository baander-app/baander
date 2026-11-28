<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\VideoMatch;

use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Model\TvEpisode;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface VideoMatchReviewReadInterface
{
    public function page(?string $cursor, int $limit, ?string $kind = null, ?Uuid $libraryId = null, ?string $reason = null): VideoMatchReviewPage;

    public function findTarget(string $kind, PublicId $publicId): ?VideoMatchTargetIdentity;

    public function findPlacement(PublicId $publicId): ?VideoPlacement;

    public function findEpisode(PublicId $publicId): ?TvEpisode;

    /**
     * @return array{items: list<array{publicId: string, seasonNumber: ?int, episodeNumber: ?int, title: string, absoluteNumber: ?int, airDate: ?string}>, total: int}
     */
    public function episodeChoices(VideoMatchTargetIdentity $owner, int $page, int $limit): array;

    /**
     * @return array{items: list<array{publicId: string, path: string, assigned: bool, episodePublicIds: list<string>, extraKind: ?string}>, total: int}
     */
    public function placementChoices(VideoMatchTargetIdentity $owner, int $page, int $limit): array;

    public function previewLibrary(Uuid $libraryId): VideoMatchLibraryPreview;

    /** @return list<VideoMatchTargetIdentity> */
    public function targetsByLibrary(Uuid $libraryId, ?VideoMatchTargetIdentity $after = null, int $limit = 100): array;
}
