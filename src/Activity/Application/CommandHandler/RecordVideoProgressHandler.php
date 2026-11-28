<?php

declare(strict_types=1);

namespace App\Activity\Application\CommandHandler;

use App\Activity\Application\Command\RecordVideoProgressCommand;
use App\Activity\Application\DTO\VideoProgressResult;
use App\Activity\Domain\Model\WatchedMark;
use App\Activity\Domain\Repository\ResumePositionRepositoryInterface;
use App\Activity\Domain\Repository\WatchedMarkRepositoryInterface;
use App\Activity\Application\Command\VideoProgressAction;
use App\Catalog\Application\Dto\WatchableTarget;
use App\Catalog\Application\Port\WatchableTitlePortInterface;
use App\Party\Application\Port\WatchMembershipPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class RecordVideoProgressHandler
{
    public function __construct(
        private WatchableTitlePortInterface $titles,
        private WatchMembershipPortInterface $parties,
        private ResumePositionRepositoryInterface $positions,
        private WatchedMarkRepositoryInterface $marks,
        private TransactionPortInterface $transaction,
        private ClockInterface $clock,
    ) {}

    #[AsMessageHandler]
    public function __invoke(RecordVideoProgressCommand $command): VideoProgressResult
    {
        $receivedAt = $this->clock->now();
        $reportedAt = $command->reportedAt > $receivedAt ? $receivedAt : $command->reportedAt;
        if (!in_array($command->titleKind, ['episode', 'movie', 'extra'], true)) {
            throw new InvalidInputException('Progress requires an episode, movie or extra.');
        }
        if (!is_finite($command->positionSeconds) || $command->positionSeconds < 0) {
            throw new InvalidInputException('The playback position must be a finite nonnegative number.');
        }

        return $this->transaction->run(function () use ($command, $reportedAt): VideoProgressResult {
            $video = $this->titles->forProgress($command->memberId, $command->videoPublicId, $command->titleKind, $command->titlePublicId)
                ?? throw new NotFoundException('Video title not found.');
            if ($this->parties->isWatching($command->memberId, $video->videoId)) {
                return new VideoProgressResult(false);
            }
            $position = $command->positionSeconds;
            $duration = $video->durationSeconds;
            $complete = false;
            if ($duration !== null && $duration > 0) {
                $position = min($position, $duration);
                $complete = $position > $duration * 0.9;
            }
            $recorded = $this->positions->record(
                $command->memberId,
                $video->videoId,
                $complete ? null : $position,
                $reportedAt,
                $complete || $command->action !== VideoProgressAction::Progress,
            );
            if ($recorded && $complete && $video->targets !== []) {
                $now = $this->clock->now();
                $marks = array_map(
                    static fn (WatchableTarget $target): WatchedMark => $target->kind === 'episode'
                        ? WatchedMark::episode($command->memberId, $target->id, $now)
                        : WatchedMark::movie($command->memberId, $target->id, $now),
                    $video->targets,
                );
                $this->marks->saveMany($marks);
            }

            return new VideoProgressResult($recorded);
        });
    }
}
