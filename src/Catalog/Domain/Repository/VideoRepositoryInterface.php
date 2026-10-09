<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\Video;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface VideoRepositoryInterface
{
    public function save(Video $video): void;

    public function findByUuid(Uuid $uuid): ?Video;

    public function findByPublicId(PublicId $publicId): ?Video;

    public function findByHash(string $hash): ?Video;

    /**
     * @return Video[]
     */
    public function findByMovie(Uuid $movieId): array;

    public function count(): int;

    /**
     * Deletes those of the videos that no movie links any more, and returns how many it deleted.
     *
     * @param list<Uuid> $videoIds
     */
    public function deleteUnlinked(array $videoIds): int;
}
