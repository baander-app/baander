<?php

declare(strict_types=1);

namespace App\Activity\Application\CommandHandler;

use App\Activity\Application\Command\SetWatchedCommand;
use App\Activity\Application\DTO\WatchedStateResult;
use App\Activity\Domain\Model\WatchedMark;
use App\Activity\Domain\Repository\ResumePositionRepositoryInterface;
use App\Activity\Domain\Repository\WatchedMarkRepositoryInterface;
use App\Catalog\Application\Dto\WatchableTarget;
use App\Catalog\Application\Port\WatchableTitlePortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class SetWatchedHandler
{
    public function __construct(
        private WatchableTitlePortInterface $titles,
        private ResumePositionRepositoryInterface $positions,
        private WatchedMarkRepositoryInterface $marks,
        private TransactionPortInterface $transaction,
        private ClockInterface $clock,
    ) {}

    #[AsMessageHandler]
    public function __invoke(SetWatchedCommand $command): WatchedStateResult
    {
        if (!in_array($command->kind, ['episode', 'season', 'series', 'movie'], true)) {
            throw new InvalidInputException('Watched state requires an episode, season, series or movie.');
        }

        return $this->transaction->run(function () use ($command): WatchedStateResult {
            $owned = $this->titles->forMark($command->memberId, $command->kind, $command->publicId)
                ?? throw new NotFoundException('Video title not found.');
            $now = $this->clock->now();
            if ($command->watched) {
                $marks = array_map(
                    static fn (WatchableTarget $target): WatchedMark => $target->kind === 'episode'
                        ? WatchedMark::episode($command->memberId, $target->id, $now)
                        : WatchedMark::movie($command->memberId, $target->id, $now),
                    $owned->targets,
                );
                $this->marks->saveMany($marks);
            } else {
                $episodes = [];
                $movies = [];
                foreach ($owned->targets as $target) {
                    if ($target->kind === 'episode') {
                        $episodes[] = $target->id;
                    } else {
                        $movies[] = $target->id;
                    }
                }
                $this->marks->deleteForMemberTargets($command->memberId, $episodes, $movies);
            }
            $this->positions->clear($command->memberId, $owned->videoIds, $now);

            return new WatchedStateResult($command->watched, count($owned->targets));
        });
    }
}
