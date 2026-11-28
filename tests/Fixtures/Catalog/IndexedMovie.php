<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoPlacementEntity;
use App\Catalog\Infrastructure\Doctrine\Query\VideoWorkAvailability;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoRepository;
use App\Catalog\Infrastructure\VideoAvailability;
use App\Library\Infrastructure\Doctrine\Entity\LibraryFileIndexEntity;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Library\Infrastructure\Filesystem\LibraryMediaFiles;
use App\Library\Infrastructure\Filesystem\MediaFileGuard;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;

final class IndexedMovie
{
    public static function availability(EntityManagerInterface $manager): VideoWorkAvailability
    {
        $clock = new NativeClock();
        $files = new LibraryMediaFiles(
            new LibraryRepository($manager, $clock),
            new LibraryFileIndexRepository($manager),
            new MediaFileGuard(),
            $manager,
            $clock,
            new NullLogger(),
        );

        return new VideoWorkAvailability($manager->getConnection(), new VideoAvailability(new VideoRepository($manager), $files));
    }

    public static function add(EntityManagerInterface $manager, MovieEntity $movie, ?VideoEntity $video = null, bool $available = true): VideoEntity
    {
        $relative = $movie->getId()->toString() . '.mp4';
        $path = rtrim($movie->getLibrary()->getPath(), '/') . '/' . $relative;
        $video ??= new VideoEntity(new PublicId(), $path, hash('xxh3', $path));
        $placement = VideoPlacement::create($movie->getLibrary()->getId(), $video->getId(), $relative, [], movieId: $movie->getId());
        $entity = new VideoPlacementEntity($movie->getLibrary(), $video, $placement->getState());
        $entity->movie = $movie;
        foreach ([
            $video,
            $entity,
            new MovieVideoEntity($movie, $video),
            new LibraryFileIndexEntity(Uuid::v7(), $movie->getLibrary()->getId(), $path, $video->getHash(), 100, 'mp4', 1, new NativeClock()->now(), $available),
        ] as $record) {
            $manager->persist($record);
        }

        return $video;
    }
}
