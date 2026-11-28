<?php

declare(strict_types=1);

use App\Catalog\Application\Dto\VideoSourceMetadata;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\Preview\VideoTimelinePreview;
use App\Catalog\Domain\Service\VideoName\EpisodeNameParser;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/video-fixture.php';

final class TvBrowserFixture
{
    /** @var array<string, mixed> */
    private array $state = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly PlaybackVideoFixture $movies,
        private readonly string $root,
        private readonly string $convertRoot,
    ) {}

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    public function execute(array $command): array
    {
        $run = $command['run'] ?? 'tv-' . bin2hex(random_bytes(8));
        if (!is_string($run) || preg_match('/\A[a-z0-9-]{1,48}\z/D', $run) !== 1) {
            throw new InvalidArgumentException('Invalid television fixture run.');
        }
        if (($command['op'] ?? '') === 'create') {
            return $this->create($run, (string) ($command['delivery'] ?? 'original'));
        }
        $path = $this->root . '/' . $run . '/tv.json';
        if (!is_file($path) && ($command['op'] ?? '') === 'cleanup') {
            return $this->movies->execute(['op' => 'cleanup', 'run' => $run]);
        }
        $this->state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return match ($command['op'] ?? '') {
            'inspect' => $this->inspect(),
            'quiesce' => $this->quiesce(),
            'cleanup' => $this->cleanup(),
            default => throw new InvalidArgumentException('Unknown television fixture operation.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function create(string $run, string $delivery): array
    {
        if (!in_array($delivery, ['original', 'encoded'], true)) {
            throw new InvalidArgumentException('Choose original or encoded delivery.');
        }
        $base = $this->movies->execute(['op' => 'create', 'run' => $run, 'delivery' => $delivery]);
        $this->state = [
            'run' => $run, 'base' => $base, 'videos' => [], 'files' => [], 'series' => [],
            'library' => $this->identity(), 'settings' => [],
        ];
        foreach (['tvdb', 'tmdb', 'local'] as $provider) {
            $key = 'metadata.provider.' . $provider . '.enabled';
            $value = $this->connection->fetchOne('SELECT value FROM system_settings WHERE key = ?', [$key]);
            $this->state['settings'][$key] = ['present' => $value !== false, 'json' => $value === false ? null : $value];
        }
        $this->save();
        $library = $this->state['library'] + [
            'slug' => 'tv-' . $run, 'name' => 'Television ' . $run,
            'path' => $base['library']['path'] . '/tv',
        ];
        $this->state['library'] = $library;
        $this->save();
        (new Filesystem())->mkdir($library['path'], 0755);
        $this->connection->insert('libraries', ['id' => $library['id'], 'public_id' => $library['publicId'], 'name' => $library['name'], 'slug' => $library['slug'], 'path' => $library['path'], 'type' => 'tv_show', 'filesystem_type' => 'local', 'created_at' => $this->now(), 'updated_at' => $this->now()]);
        foreach ([$base['users'][0], $base['users'][1], $base['observer']] as $user) {
            $this->connection->insert('user_library_access', ['user_id' => $user['id'], 'library_id' => $library['id']]);
        }
        $series = $this->series('Series', 'Owned series ' . $run, true);
        $season = $this->season($series, 1);
        $specials = $this->season($series, 0);
        $first = $this->episode($series, $season, 1, 'Pilot');
        $second = $this->episode($series, $season, 2, 'Second episode');
        $this->episode($series, $season, 3, 'Missing episode');
        $special = $this->episode($series, $specials, 1, 'Special episode');
        $absolute = $this->episode($series, null, null, 'Episode 7', 7);
        $daily = $this->episode($series, null, null, 'Episode 2026-11-28', airDate: '2026-11-28');
        $span = $this->file('Series/Season 1/Show.S01E01-E02.mp4', $series, [$first, $second]);
        $this->file('Series/Specials/Show.S00E01.mp4', $series, [$special]);
        $this->file('Series/Show.007.mp4', $series, [$absolute]);
        $this->file('Series/Show.2026-11-28.mp4', $series, [$daily]);
        $extra = $this->file('Series/trailer.mp4', $series, extra: 'trailer');
        $review = $this->series('Review', 'Review stub ' . $run, false);
        $reviewSeason = $this->season($review, 1);
        $reviewEpisode = $this->episode($review, $reviewSeason, 1, 'Review episode');
        $reviewFile = $this->file('Review/Season 1/Show.S01E01.mp4', $review, [$reviewEpisode]);
        $matchedTitle = 'Matched series ' . $run;
        $this->sidecar('Review/tvshow.nfo', '<tvshow><title>' . $matchedTitle . '</title></tvshow>');
        $this->sidecar('Review/S01E01.nfo', '<episodedetails><title>Review episode</title><season>1</season><episode>1</episode></episodedetails>');
        $this->sidecar('Series/tvshow.nfo', '<tvshow><title>' . $series['name'] . '</title></tvshow>');
        foreach ([1 => 'Pilot', 2 => 'Second episode', 3 => 'Missing episode'] as $number => $name) {
            $this->sidecar('Series/S01E0' . $number . '.nfo', '<episodedetails><title>' . $name . '</title><season>1</season><episode>' . $number . '</episode></episodedetails>');
        }
        $this->connection->insert('video_match_states', [
            'id' => (string) Uuid::v7(), 'series_id' => $review['id'], 'status' => 'needs_review',
            'source' => 'ingest', 'due_at' => $this->now(), 'reason' => 'no_candidates', 'checked_at' => $this->now(),
        ]);
        $this->connection->update('movies', ['matched_provider' => 'tmdb', 'provider_id' => '99999999'], ['id' => $base['movie']['id']]);
        $movieExtra = $this->file('movie-behind-the-scenes.mp4', null, extra: 'behind_the_scenes');
        $alternate = $this->file('movie-alternate.mp4', null);
        $this->connection->insert('resume_positions', [
            'id' => (string) Uuid::v7(), 'user_id' => $base['users'][0]['id'], 'video_id' => $alternate['videoId'],
            'position_seconds' => 8, 'reported_at' => $this->now(), 'last_played_at' => $this->now(),
        ]);
        $public = [
            'run' => $run, 'delivery' => $delivery, 'durationSeconds' => 30,
            'library' => $this->publicLibrary($library), 'movieLibrary' => $this->publicLibrary($base['library'] + ['name' => 'Movies ' . $run]),
            'series' => $this->publicTitle($series),
            'season' => ['publicId' => $season['publicId'], 'number' => 1, 'ownedCount' => 2, 'totalCount' => 3],
            'specials' => ['publicId' => $specials['publicId'], 'number' => 0, 'ownedCount' => 1, 'totalCount' => 1],
            'episodes' => array_map($this->publicTitle(...), compact('first', 'second', 'special', 'absolute', 'daily')),
            'span' => $this->publicFile($span), 'extra' => $this->publicFile($extra) + ['name' => 'Trailer'],
            'movie' => [
                'publicId' => $base['movie']['publicId'], 'title' => 'Owned playback movie ' . $run,
                'videoPublicId' => $base['video']['publicId'], 'alternateVideoPublicId' => $alternate['videoPublicId'],
                'extra' => $this->publicFile($movieExtra) + ['name' => 'Behind the scenes'],
            ],
            'review' => [
                'seriesPublicId' => $review['publicId'], 'placementPublicId' => $reviewFile['publicId'],
                'originalTitle' => $review['name'], 'matchedTitle' => $matchedTitle,
                'candidateId' => $review['id'], 'orderingId' => 'aired',
            ],
        ];
        $this->state['public'] = $public;
        $this->save();

        return ['manifest' => $public, 'users' => ['member' => $base['users'][0], 'secondMember' => $base['users'][1], 'admin' => $base['observer']]];
    }

    /**
     * @return array{id:string,publicId:string}
     */
    private function identity(): array
    {
        return ['id' => (string) Uuid::v7(), 'publicId' => (string) new PublicId()];
    }

    /**
     * @return array<string, mixed>
     */
    private function series(string $folder, string $name, bool $matched): array
    {
        $series = $this->identity() + ['name' => $name, 'folder' => $folder];
        $this->state['series'][] = $series;
        $this->save();
        $this->connection->insert('tv_series', [
            'id' => $series['id'], 'public_id' => $series['publicId'], 'library_id' => $this->state['library']['id'],
            'folder_path' => $folder, 'name' => $name, 'matched_provider' => $matched ? 'local' : null,
            'provider_id' => $matched ? $series['id'] : null, 'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);

        return $series;
    }

    /**
     * @param array<string, mixed> $series
     * @return array{id:string,publicId:string}
     */
    private function season(array $series, int $number): array
    {
        $season = $this->identity();
        $this->connection->insert('tv_seasons', ['id' => $season['id'], 'public_id' => $season['publicId'], 'series_id' => $series['id'], 'season_number' => $number]);
        return $season;
    }

    /**
     * @param array<string, mixed> $series
     * @param array<string, mixed>|null $season
     * @return array<string, mixed>
     */
    private function episode(array $series, ?array $season, ?int $number, string $name, ?int $absolute = null, ?string $airDate = null): array
    {
        $episode = $this->identity() + ['name' => $name];
        $this->connection->insert('tv_episodes', [
            'id' => $episode['id'], 'public_id' => $episode['publicId'], 'series_id' => $series['id'],
            'season_id' => $season['id'] ?? null, 'episode_number' => $number, 'absolute_number' => $absolute,
            'air_date' => $airDate, 'name' => $name,
        ]);
        return $episode;
    }

    /**
     * @param array<string, mixed>|null $series
     * @param list<array<string, mixed>> $episodes
     * @return array<string, mixed>
     */
    private function file(string $relative, ?array $series, array $episodes = [], ?string $extra = null): array
    {
        $base = $this->state['base'];
        $library = $series === null ? $base['library'] : $this->state['library'];
        $path = $library['path'] . '/' . $relative;
        $video = $this->identity();
        $placement = $this->identity() + ['videoId' => $video['id'], 'videoPublicId' => $video['publicId'], 'path' => $path, 'libraryId' => $library['id']];
        $this->state['videos'][] = $placement;
        $this->state['files'][] = $path;
        $this->save();
        (new Filesystem())->mkdir(dirname($path), 0755);
        (new Process([
            '/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-i', $base['video']['path'],
            '-map', '0', '-c', 'copy', '-metadata', 'title=' . $this->state['run'] . ' ' . $relative,
            '-movflags', '+faststart', $path,
        ], timeout: 30))->mustRun();
        chmod($path, 0644);
        $hash = hash_file('xxh3', $path);
        if (!is_string($hash)) {
            throw new RuntimeException('Cannot hash an owned video.');
        }
        $source = $this->connection->fetchAssociative('SELECT duration, width, height, video_bitrate, framerate, probe FROM videos WHERE id = ?', [$base['video']['id']]);
        if ($source === false) {
            throw new RuntimeException('Missing generated movie source.');
        }
        $probe = VideoProbeResult::fromSerialized(json_decode($source['probe'], true, flags: JSON_THROW_ON_ERROR));
        $placement['previewIdentity'] = VideoTimelinePreview::cacheIdentity(new VideoSourceMetadata(
            videoId: Uuid::fromString($video['id']), publicId: $video['publicId'], filePath: $path,
            filename: basename($path), size: (int) filesize($path), mimeType: 'video/mp4', hash: $hash,
            containerFormat: $probe->containerFormat, videoCodec: $probe->videoCodec,
            videoStreamIndex: $probe->videoStreamIndex, audioStreams: [], width: $probe->width,
            height: $probe->height, framerate: $probe->framerate, duration: $probe->duration,
            containerStartTime: $probe->containerStartTime, sourceTimestampOrigin: $probe->sourceTimestampOrigin,
        ));
        $this->state['videos'][array_key_last($this->state['videos'])] = $placement;
        $this->save();
        $this->connection->transactional(function () use ($video, $placement, $library, $relative, $series, $episodes, $extra, $path, $hash, $source, $base): void {
            $this->connection->insert('videos', [
                'id' => $video['id'], 'public_id' => $video['publicId'], 'path' => $path, 'hash' => $hash,
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ] + $source);
            $parse = $series === null ? [] : new EpisodeNameParser()->parse($relative)->jsonSerialize();
            $this->connection->insert('video_placements', [
                'id' => $placement['id'], 'public_id' => $placement['publicId'], 'library_id' => $library['id'],
                'video_id' => $video['id'], 'series_id' => $series['id'] ?? null,
                'movie_id' => $series === null ? $base['movie']['id'] : null, 'relative_path' => $relative,
                'raw_parse' => json_encode($parse, JSON_THROW_ON_ERROR), 'extra_kind' => $extra,
                'created_at' => $this->now(),
            ]);
            foreach ($episodes as $sort => $episode) {
                $this->connection->insert('video_placement_episode', ['id' => (string) Uuid::v7(), 'placement_id' => $placement['id'], 'episode_id' => $episode['id'], 'sort_order' => $sort]);
            }
            if ($series === null && $extra === null) {
                $this->connection->insert('movie_video', ['id' => (string) Uuid::v7(), 'movie_id' => $base['movie']['id'], 'video_id' => $video['id'], 'sort_order' => 1]);
            }
            $this->index($library['id'], $path, $hash);
        });
        return $placement;
    }

    private function sidecar(string $relative, string $contents): void
    {
        $path = $this->state['library']['path'] . '/' . $relative;
        $this->state['files'][] = $path;
        $this->save();
        (new Filesystem())->mkdir(dirname($path), 0755);
        if (file_put_contents($path, $contents) !== strlen($contents) || !chmod($path, 0644)) {
            throw new RuntimeException('Cannot publish an owned sidecar.');
        }
        $hash = hash_file('xxh3', $path);
        if (!is_string($hash)) {
            throw new RuntimeException('Cannot hash an owned sidecar.');
        }
        $this->index($this->state['library']['id'], $path, $hash);
    }

    private function index(string $libraryId, string $path, string $hash): void
    {
        $this->connection->insert('library_file_index', [
            'id' => (string) Uuid::v7(), 'library_id' => $libraryId, 'path' => $path, 'hash' => $hash,
            'size' => (int) filesize($path), 'extension' => pathinfo($path, PATHINFO_EXTENSION),
            'modified_at' => (int) filemtime($path), 'discovered_at' => $this->now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function inspect(): array
    {
        $members = [];
        foreach (['member' => $this->state['base']['users'][0], 'secondMember' => $this->state['base']['users'][1]] as $key => $user) {
            $resumes = $this->connection->fetchAllAssociative('SELECT video.public_id AS "videoPublicId", resume.position_seconds AS "positionSeconds", resume.reported_at AS "reportedAt", resume.last_played_at AS "lastPlayedAt" FROM resume_positions resume JOIN videos video ON video.id = resume.video_id WHERE resume.user_id = ? ORDER BY video.public_id', [$user['id']]);
            foreach ($resumes as &$resume) {
                $resume['positionSeconds'] = $resume['positionSeconds'] === null ? null : (float) $resume['positionSeconds'];
            }
            unset($resume);
            $members[$key] = [
                'resumes' => $resumes,
                'watchedEpisodePublicIds' => $this->connection->fetchFirstColumn('SELECT episode.public_id FROM watched_marks mark JOIN tv_episodes episode ON episode.id = mark.episode_id WHERE mark.user_id = ? ORDER BY episode.public_id', [$user['id']]),
                'watchedMoviePublicIds' => $this->connection->fetchFirstColumn('SELECT movie.public_id FROM watched_marks mark JOIN movies movie ON movie.id = mark.movie_id WHERE mark.user_id = ? ORDER BY movie.public_id', [$user['id']]),
            ];
        }
        $reviewId = $this->state['series'][1]['id'];
        $review = $this->connection->fetchAssociative('SELECT name, matched_provider AS "matchedProvider", hand_matched AS "matchedManually", ordering FROM tv_series WHERE id = ?', [$reviewId]);
        $placements = $this->connection->fetchAllAssociative('SELECT placement.id, placement.public_id AS "publicId", placement.extra_kind AS "extraKind", placement.assigned, COALESCE(file.available, FALSE) AS available FROM video_placements placement JOIN videos video ON video.id = placement.video_id JOIN libraries library ON library.id = placement.library_id LEFT JOIN library_file_index file ON file.library_id = library.id AND file.path = library.path || \'/\' || placement.relative_path AND file.hash = video.hash WHERE placement.library_id IN (?, ?) ORDER BY placement.public_id', [$this->state['library']['id'], $this->state['base']['library']['id']]);
        $episodeLinks = [];
        if ($placements !== []) {
            $links = $this->connection->fetchAllAssociative(
                'SELECT link.placement_id, episode.public_id FROM video_placement_episode link JOIN tv_episodes episode ON episode.id = link.episode_id WHERE link.placement_id IN (?) ORDER BY link.placement_id, link.sort_order',
                [array_column($placements, 'id')],
                [ArrayParameterType::STRING],
            );
            foreach ($links as $link) {
                $episodeLinks[$link['placement_id']][] = $link['public_id'];
            }
        }
        foreach ($placements as &$placement) {
            $placement['episodePublicIds'] = $episodeLinks[$placement['id']] ?? [];
            unset($placement['id']);
        }
        unset($placement);
        $order = $this->connection->fetchOne('SELECT provider_ids FROM library_provider_orders WHERE library_id = ?', [$this->state['library']['id']]);
        return $members + [
            'review' => $review, 'placements' => $placements,
            'providerOrder' => $order === false ? ['tvdb', 'tmdb', 'local'] : json_decode($order, true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @return array{complete:bool}
     */
    private function quiesce(): array
    {
        $seriesIds = array_column($this->state['series'], 'id');
        $deadline = microtime(true) + 45;
        do {
            $this->connection->beginTransaction();
            try {
                $claims = $this->connection->fetchFirstColumn('SELECT claim_id FROM video_match_states WHERE series_id IN (?) OR movie_id = ? FOR UPDATE', [$seriesIds, $this->state['base']['movie']['id']], [ArrayParameterType::STRING, 'string']);
                $matching = array_any($claims, static fn ($claim): bool => $claim !== null);
                if (!$matching) {
                    $this->connection->executeStatement('DELETE FROM video_match_states WHERE series_id IN (?) OR movie_id = ?', [$seriesIds, $this->state['base']['movie']['id']], [ArrayParameterType::STRING, 'string']);
                }
                $this->connection->commit();
            } catch (Throwable $error) {
                $this->connection->rollBack();
                throw $error;
            }
            if (!$matching) {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
        if ($matching) {
            throw new RuntimeException('Owned metadata workers did not quiesce; provider switches and ownership retained.');
        }
        $videoIds = [$this->state['base']['video']['id'], ...array_column($this->state['videos'], 'videoId')];
        $this->connection->executeStatement("UPDATE transcode_runtime_state runtime SET desired = jsonb_set(runtime.desired, '{status}', '\"cancelled\"'::jsonb), desired_revision = runtime.desired_revision + 1, updated_at = clock_timestamp() FROM transcode_jobs job WHERE runtime.job_id = job.id AND job.video_id IN (?)", [$videoIds], [ArrayParameterType::STRING]);
        $deadline = microtime(true) + 45;
        do {
            $live = (int) $this->connection->fetchOne('SELECT count(*) FROM transcode_runtime_state runtime JOIN transcode_jobs job ON job.id = runtime.job_id WHERE job.video_id IN (?) AND runtime.claim_token IS NOT NULL AND runtime.lease_expires_at > clock_timestamp()', [$videoIds], [ArrayParameterType::STRING]);
            if ($live === 0) {
                return ['complete' => true];
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Owned video workers did not quiesce; ownership retained.');
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanup(): array
    {
        $this->quiesce();
        $this->connection->transactional(function (): void {
            $this->connection->delete('libraries', ['id' => $this->state['library']['id']]);
            foreach ($this->state['videos'] as $video) {
                $this->connection->delete('video_placements', ['id' => $video['id']]);
                $this->connection->delete('movie_video', ['video_id' => $video['videoId']]);
                $this->connection->delete('videos', ['id' => $video['videoId']]);
            }
            foreach ($this->state['settings'] as $key => $original) {
                if ($original['present']) {
                    $this->connection->executeStatement('INSERT INTO system_settings (key, value) VALUES (?, CAST(? AS JSONB)) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()', [$key, $original['json']]);
                } else {
                    $this->connection->delete('system_settings', ['key' => $key]);
                }
            }
        });
        $filesystem = new Filesystem();
        foreach ($this->state['videos'] as $video) {
            if (isset($video['previewIdentity'])) {
                VideoTimelinePreview::removeCachedSource($this->convertRoot . '/timeline-previews', $video['previewIdentity']);
            }
            $filesystem->remove($this->convertRoot . '/' . $video['videoId']);
        }
        foreach ($this->state['files'] as $path) {
            $filesystem->remove($path);
        }
        $filesystem->remove($this->state['library']['path']);
        return $this->movies->execute(['op' => 'cleanup', 'run' => $this->state['run']]);
    }

    /**
     * @param array<string, mixed> $title
     * @return array{publicId:string,name:string}
     */
    private function publicTitle(array $title): array { return ['publicId' => $title['publicId'], 'name' => $title['name']]; }
    /**
     * @param array<string, mixed> $library
     * @return array{publicId:string,slug:string,name:string}
     */
    private function publicLibrary(array $library): array { return ['publicId' => $library['publicId'], 'slug' => $library['slug'], 'name' => $library['name']]; }
    /**
     * @param array<string, mixed> $file
     * @return array{videoPublicId:string,placementPublicId:string}
     */
    private function publicFile(array $file): array { return ['videoPublicId' => $file['videoPublicId'], 'placementPublicId' => $file['publicId']]; }
    private function now(): string { return (new DateTimeImmutable())->format('Y-m-d H:i:s.uP'); }

    private function save(): void
    {
        $path = $this->root . '/' . $this->state['run'] . '/tv.json';
        $json = json_encode($this->state, JSON_THROW_ON_ERROR);
        if (file_put_contents($path . '.tmp', $json) !== strlen($json) || !chmod($path . '.tmp', 0600) || !rename($path . '.tmp', $path)) {
            throw new RuntimeException('Cannot persist television fixture ownership.');
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    (new Dotenv())->bootEnv(dirname(__DIR__, 3) . '/.env');
    $environment = static function (string $key): string {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if (!is_string($value) || !str_starts_with($value, '/')) {
            throw new RuntimeException('A configured absolute storage path is required.');
        }
        return rtrim($value, '/');
    };
    $root = dirname(__DIR__, 3) . '/var/playback-tv';
    $mediaRoot = $environment('MEDIA_STORAGE_PATH') . '/.playback-tv';
    $convertRoot = $environment('CONVERT_STORAGE_PATH');
    $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
    $connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse((string) $url));
    $connection->executeStatement("SET TIME ZONE 'UTC'");
    $redis = $_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? getenv('REDIS_URL');
    $base = new LiveCapacityFixture($connection, $root, $mediaRoot, new RedisClientFactory((string) $redis));
    $movies = new PlaybackVideoFixture($connection, $base, $root, $mediaRoot, $convertRoot);
    $fixture = new TvBrowserFixture($connection, $movies, $root, $convertRoot);
    try {
        while (($line = fgets(STDIN)) !== false) {
            $command = [];
            try {
                $command = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $receipt = $fixture->execute($command);
                $response = ['requestId' => $command['requestId'] ?? null, 'op' => $command['op'] ?? null, 'run' => $command['run'] ?? $receipt['manifest']['run'] ?? null, 'ok' => true, 'complete' => true, 'receipt' => $receipt];
            } catch (Throwable $error) {
                $response = ['requestId' => $command['requestId'] ?? null, 'op' => $command['op'] ?? null, 'run' => $command['run'] ?? null, 'ok' => false, 'complete' => true, 'error' => $error->getMessage()];
            }
            fwrite(STDOUT, json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            fflush(STDOUT);
        }
    } finally {
        $base->close();
    }
}
