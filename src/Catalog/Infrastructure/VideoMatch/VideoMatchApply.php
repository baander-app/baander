<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\VideoMatch;

use App\Catalog\Application\Port\VideoMatchApplyInterface;
use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoMatchState;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\Repository\VideoMatchStateRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvSeriesEntity;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Shared\Application\Port\TransactionPortInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;

final readonly class VideoMatchApply implements VideoMatchApplyInterface
{
    public function __construct(
        private EntityManagerInterface $manager,
        private TransactionPortInterface $transaction,
        private VideoMatchStateRepositoryInterface $states,
        private MovieRepositoryInterface $movies,
        private TvCatalogRepositoryInterface $catalog,
        private ClockInterface $clock,
    ) {}

    public function apply(VideoMatchState $claim, VideoMatchTarget $target, VideoProviderId $provider, VideoTitle $metadata): bool
    {
        $claimId = $claim->claimId;
        if ($claimId === null || !self::owns($claim, $target) || !$provider->serves($target->request->kind)) {
            return false;
        }

        return $this->transaction->run(function () use ($claim, $claimId, $target, $provider, $metadata): bool {
            // Lock the title before its FK-dependent claim row, as a title delete does.
            $title = $this->lockTitle($target);
            if ($title === null || $target->request->libraryId?->equals($title->getLibraryId()) !== true) {
                return false;
            }
            $live = $this->states->lockClaimForApply($claim->id, $claimId);
            if ($live === null || !self::owns($live, $target)
                || $this->states->completeClaim($live->id, $claimId, $this->clock->now()) === null
            ) {
                return false;
            }

            $externalIds = array_replace($title->getExternalIds(), $metadata->entry->externalIds, [$provider->value => $metadata->entry->id]);
            $title->match($provider->value, $metadata->entry->id, $externalIds, $title->isHandMatched());
            if ($title instanceof Movie) {
                $this->movies->saveMatchIdentity($title);
            } else {
                $this->catalog->saveSeries($title);
                $this->catalog->flush();
            }

            return true;
        });
    }

    private static function owns(VideoMatchState $state, VideoMatchTarget $target): bool
    {
        $owner = $target->request->kind === VideoKind::Series ? $state->seriesId : $state->movieId;

        return $owner?->equals($target->id) === true;
    }

    private function lockTitle(VideoMatchTarget $target): Movie|TvSeries|null
    {
        $series = $target->request->kind === VideoKind::Series;
        $entity = $this->manager->createQueryBuilder()
            ->select('title')
            ->from($series ? TvSeriesEntity::class : MovieEntity::class, 'title')
            ->where('title.id = :id')
            ->setParameter('id', $target->id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
        if ($entity === null) {
            return null;
        }

        return $series ? $this->catalog->findSeriesByUuid($target->id) : $this->movies->findByUuid($target->id);
    }
}
