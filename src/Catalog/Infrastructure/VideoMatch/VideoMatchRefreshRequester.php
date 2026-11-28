<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\VideoMatch;

use App\Catalog\Application\Message\RefreshVideoMatchMessage;
use App\Catalog\Application\Port\VideoMatchRefreshInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvSeriesEntity;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class VideoMatchRefreshRequester implements VideoMatchRefreshInterface
{
    private const int PAGE_SIZE = 100;

    public function __construct(
        private EntityManagerInterface $manager,
        private MessageBusInterface $bus,
        private JobCancellationCheckpointInterface $cancellation,
        private ClockInterface $clock,
    ) {}

    public function requestLibrary(Uuid $libraryId): int
    {
        return $this->request($libraryId);
    }

    public function requestAll(): int
    {
        return $this->request(null);
    }

    private function request(?Uuid $libraryId): int
    {
        $queued = 0;
        foreach (VideoKind::cases() as $kind) {
            $last = null;
            do {
                $ids = $this->matchedTitleIds($kind, $libraryId, $last);
                foreach ($ids as $id) {
                    $this->cancellation->check();
                    $this->bus->dispatch(new RefreshVideoMatchMessage($kind, $id, Uuid::v7(), $this->clock->now()));
                    $last = $id;
                    ++$queued;
                }
            } while (count($ids) === self::PAGE_SIZE);
        }

        return $queued;
    }

    /** @return list<Uuid> */
    private function matchedTitleIds(VideoKind $kind, ?Uuid $libraryId, ?Uuid $after): array
    {
        $entity = $kind === VideoKind::Series ? TvSeriesEntity::class : MovieEntity::class;
        $query = $this->manager->createQueryBuilder()
            ->select('title.id')
            ->from($entity, 'title')
            ->where('title.matchedProvider IS NOT NULL AND title.providerId IS NOT NULL')
            ->orderBy('title.id', 'ASC')
            ->setMaxResults(self::PAGE_SIZE);
        if ($libraryId !== null) {
            $query->andWhere('title.library = :library')->setParameter('library', $libraryId, 'uuid');
        }
        if ($after !== null) {
            $query->andWhere('title.id > :after')->setParameter('after', $after, 'uuid');
        }

        return array_map(
            static fn (mixed $id): Uuid => $id instanceof Uuid ? $id : Uuid::fromString((string) $id),
            $query->getQuery()->getSingleColumnResult(),
        );
    }
}
