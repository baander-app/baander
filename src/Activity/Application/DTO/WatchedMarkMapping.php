<?php

declare(strict_types=1);

namespace App\Activity\Application\DTO;

use App\Activity\Domain\Model\WatchedMark;
use App\Shared\Domain\Model\Uuid;

final readonly class WatchedMarkMapping
{
    /** @var list<WatchedMark> */
    public array $marks;
    /** @var list<WatchedMark> */
    public array $removedMarks;

    /**
     * @param list<WatchedMark> $snapshot
     * @param list<WatchedMarkVideoMove> $videos
     * @param list<Uuid> $retainedEpisodeIds
     * @param list<WatchedMarkEpisodeMerge> $mergedEpisodes
     */
    public function __construct(array $snapshot, array $videos, array $retainedEpisodeIds, array $mergedEpisodes = [])
    {
        $targets = [];
        foreach ($videos as $video) {
            foreach ($video->beforeEpisodeIds as $source) {
                foreach ($video->afterEpisodeIds as $target) {
                    $targets[$source->toString()][$target->toString()] = $target;
                }
                $targets[$source->toString()] ??= [];
            }
        }
        $merged = [];
        foreach ($mergedEpisodes as $merge) {
            $targets[$merge->sourceEpisodeId->toString()][$merge->targetEpisodeId->toString()] = $merge->targetEpisodeId;
            $merged[$merge->sourceEpisodeId->toString()] = true;
        }
        $retained = array_fill_keys(array_map(static fn (Uuid $id): string => $id->toString(), $retainedEpisodeIds), true);
        $marks = [];
        $removed = [];
        foreach ($snapshot as $mark) {
            $source = $mark->getEpisodeId()?->toString();
            if ($source === null || !array_key_exists($source, $targets)) {
                continue;
            }
            foreach ($targets[$source] as $target) {
                $key = $mark->getUserId()->toString() . ':' . $target->toString();
                if (!isset($marks[$key]) || $marks[$key]->getWatchedAt() < $mark->getWatchedAt()) {
                    $marks[$key] = WatchedMark::episode($mark->getUserId(), $target, $mark->getWatchedAt());
                }
            }
            if (!isset($retained[$source]) || isset($merged[$source])) {
                $removed[] = $mark;
            }
        }
        $this->marks = array_values($marks);
        $this->removedMarks = $removed;
    }
}
