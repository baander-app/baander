<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Query;

use App\Catalog\Application\Port\VideoAvailabilityPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * @phpstan-type WorkKind 'movie'|'series'|'episode'
 * @phpstan-type Work array{kind:WorkKind,id:string,libraryId:string}
 * @phpstan-type FileLink array{work_id:string,library_id:string,video_id:string,placement_public_id:string,extra_kind:?string,public_id:string,width:?int,height:?int,duration:?int,video_bitrate:?int}
 */
final readonly class VideoWorkAvailability
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private Connection $connection,
        private VideoAvailabilityPortInterface $availability,
    ) {}

    /**
     * @param list<Work> $works Already ACL-scoped Catalog identities.
     * @return list<Work>
     */
    public function visibleWorks(array $works): array
    {
        $groups = [];
        foreach ($works as $work) {
            $groups[$work['kind']][$work['id']][$work['libraryId']] = true;
        }
        $visible = [];
        foreach ($groups as $kind => $workLibraries) {
            foreach ($this->availableFileLinks($kind, $workLibraries) as $file) {
                $visible[$kind][$file['id']][$file['libraryId']] = true;
            }
        }

        return array_values(array_filter(
            $works,
            static fn (array $work): bool => isset($visible[$work['kind']][$work['id']][$work['libraryId']]),
        ));
    }

    /**
     * @param WorkKind $kind
     * @param array<string,string> $workLibraries Library UUID by already ACL-scoped work UUID.
     * @return array<string,list<FileLink>>
     */
    public function files(string $kind, array $workLibraries, bool $extrasOnly = false): array
    {
        $librariesByWork = array_map(static fn (string $id): array => [$id => true], $workLibraries);
        $files = [];
        foreach ($this->availableFileLinks($kind, $librariesByWork, $extrasOnly) as $file) {
            $files[$file['id']][] = $file['link'];
        }

        return $files;
    }

    /**
     * @param WorkKind $kind
     * @param array<string,array<string,true>> $librariesByWork
     * @return iterable<array{id:string,libraryId:string,link:FileLink}>
     */
    private function availableFileLinks(string $kind, array $librariesByWork, bool $extrasOnly = false): iterable
    {
        [$owner, $from, $condition, $order] = match ($kind) {
            'episode' => [
                'episode.id',
                'video_placement_episode link JOIN video_placements p ON p.id = link.placement_id JOIN tv_episodes episode ON episode.id = link.episode_id',
                'episode.retired = FALSE AND p.series_id = episode.series_id AND p.extra_kind IS NULL',
                'p.sort_order, p.id',
            ],
            'movie' => ['p.movie_id', 'video_placements p', 'TRUE', 'p.sort_order, p.id'],
            'series' => [
                'p.series_id',
                'video_placements p',
                <<<'SQL'
                    (p.extra_kind IS NOT NULL OR EXISTS (
                        SELECT 1
                        FROM video_placement_episode link
                        JOIN tv_episodes episode ON episode.id = link.episode_id
                        WHERE link.placement_id = p.id AND episode.series_id = p.series_id AND episode.retired = FALSE
                    ))
                    SQL,
                'p.sort_order, p.id',
            ],
        };
        if ($extrasOnly) {
            $condition .= ' AND p.extra_kind IS NOT NULL';
        }
        foreach (array_chunk($librariesByWork, self::BATCH_SIZE, preserve_keys: true) as $batch) {
            $links = $this->connection->iterateAssociative(
                <<<SQL
                    SELECT $owner AS work_id, p.library_id, p.video_id, p.public_id AS placement_public_id, p.extra_kind,
                           v.public_id, v.width, v.height, v.duration, v.video_bitrate
                    FROM $from JOIN videos v ON v.id = p.video_id
                    WHERE $owner IN (:works) AND $condition
                    ORDER BY $owner, $order
                    SQL,
                ['works' => array_keys($batch)],
                ['works' => ArrayParameterType::STRING],
            );
            $pending = [];
            foreach ($links as $link) {
                /** @var FileLink $link */
                if (!isset($batch[$link['work_id']][$link['library_id']])) {
                    continue;
                }
                $pending[] = $link;
                if (count($pending) === self::BATCH_SIZE) {
                    yield from $this->availableChunk($pending);
                    $pending = [];
                }
            }
            yield from $this->availableChunk($pending);
        }
    }

    /**
     * @param list<FileLink> $links
     * @return iterable<array{id:string,libraryId:string,link:FileLink}>
     */
    private function availableChunk(array $links): iterable
    {
        $libraries = [];
        foreach ($links as $link) {
            $libraries[$link['library_id']][$link['video_id']] = Uuid::fromString($link['video_id']);
        }
        $available = [];
        foreach ($libraries as $library => $ids) {
            $scope = LibraryReadScope::restricted([Uuid::fromString($library)]);
            $available[$library] = $this->availability->forVideos(array_values($ids), $scope);
        }
        foreach ($links as $link) {
            if ($available[$link['library_id']][$link['video_id']] ?? false) {
                yield ['id' => $link['work_id'], 'libraryId' => $link['library_id'], 'link' => $link];
            }
        }
    }
}
