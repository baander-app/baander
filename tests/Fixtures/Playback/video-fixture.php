<?php

declare(strict_types=1);

use App\Catalog\Application\Dto\VideoSourceMetadata;
use App\Catalog\Application\Port\DirectorCredit;
use App\Catalog\Domain\ReadModel\DirectorReadView;
use App\Catalog\Infrastructure\DirectorService;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\PersonWriteLock;
use App\Catalog\Infrastructure\Doctrine\Repository\DirectorRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Kernel;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\Preview\VideoTimelinePreview;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/LiveUpdates/capacity-fixture.php';

/** Owns one generated movie and the capacity fixture's account/library scope. */
final class PlaybackVideoFixture
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LiveCapacityFixture $base,
        private readonly string $root,
        private readonly string $mediaRoot,
        private readonly string $convertRoot,
    ) {}

    /** @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    public function execute(array $command): array
    {
        $run = $command['run'] ?? bin2hex(random_bytes(10));
        if (!is_string($run) || preg_match('/\A[a-z0-9-]{1,48}\z/D', $run) !== 1) {
            throw new InvalidArgumentException('Invalid video fixture run.');
        }
        return match ($command['op'] ?? '') {
            'create' => $this->create(
                $run,
                $command['delivery'] ?? 'encoded',
                ($command['cinema'] ?? false) === true,
                ($command['quality'] ?? false) === true,
                ($command['directors'] ?? false) === true,
                ($command['departure'] ?? false) === true,
            ),
            'cleanup' => $this->cleanup($run),
            'inspect' => $this->inspect($run),
            default => throw new InvalidArgumentException('Unknown video fixture operation.'),
        };
    }

    /** @param array<string, mixed>|null $scope
     * @return array<string, mixed>
     */
    private function create(
        string $run,
        string $delivery,
        bool $cinema,
        bool $quality,
        bool $directors,
        bool $departure = false,
        ?array $scope = null,
    ): array {
        if (!in_array($delivery, ['encoded', 'original', 'copy'], true)) {
            throw new InvalidArgumentException('Unknown fixture delivery mode.');
        }
        $manifest = $scope ?? $this->base->execute(['op' => 'create', 'run' => $run]);
        $mediaDirectory = $this->movieMediaDirectory($run, $scope['run'] ?? null);
        if ($scope !== null) {
            $manifest['run'] = $run;
            $manifest['parentRun'] = $scope['run'];
            unset($manifest['successorRun'], $manifest['successor']);
            foreach ([$this->root . '/' . $run, $mediaDirectory] as $directory) {
                if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
                    throw new RuntimeException('Cannot create the successor movie directory.');
                }
            }
            if (!chmod($mediaDirectory, 0755)) {
                throw new RuntimeException('Cannot publish the successor movie directory.');
            }
        }
        $duration = $departure ? 180 : 30;
        $manifest['movie'] = [
            'id' => (string) new Uuid(),
            'publicId' => (string) new PublicId(),
        ];
        $extension = $delivery === 'copy' || $cinema ? 'mkv' : 'mp4';
        $manifest['video'] = [
            'id' => (string) new Uuid(),
            'publicId' => (string) new PublicId(),
            'path' => $mediaDirectory . '/owned.' . $extension,
        ];
        $manifest['movieVideoId'] = (string) new Uuid();
        // Persist exact ownership before creating files or rows, including failed runs.
        $this->save($run, $manifest);
        if ($cinema) {
            $this->generateCinemaSource($run, $manifest['video']['path'], $quality);
        } else {
            $this->generateSource($run, $manifest['video']['path'], $delivery, $extension, $quality, $duration);
        }
        if (!chmod($manifest['video']['path'], 0644)) {
            throw new RuntimeException('Cannot publish the owned video to source workers.');
        }
        $probe = new Process([
            '/usr/bin/ffprobe', '-v', 'error',
            '-show_format', '-show_streams', '-show_chapters',
            '-of', 'json', $manifest['video']['path'],
        ], timeout: 30);
        $probe->mustRun();
        $metadata = VideoProbeResult::fromProbeOutput(
            json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR),
        );
        $hash = hash_file('xxh3', $manifest['video']['path']);
        if (!is_string($hash)) {
            throw new RuntimeException('Cannot hash the owned video.');
        }
        $manifest['previewIdentity'] = VideoTimelinePreview::cacheIdentity(new VideoSourceMetadata(
            videoId: Uuid::fromString($manifest['video']['id']),
            publicId: $manifest['video']['publicId'],
            filePath: $manifest['video']['path'],
            filename: basename($manifest['video']['path']),
            size: (int) filesize($manifest['video']['path']),
            mimeType: $extension === 'mkv' ? 'video/x-matroska' : 'video/mp4',
            hash: $hash,
            containerFormat: $metadata->containerFormat,
            videoCodec: $metadata->videoCodec,
            videoStreamIndex: $metadata->videoStreamIndex,
            audioStreams: [],
            width: $metadata->width,
            height: $metadata->height,
            framerate: $metadata->framerate,
            duration: $metadata->duration,
            containerStartTime: $metadata->containerStartTime,
            sourceTimestampOrigin: $metadata->sourceTimestampOrigin,
        ));
        $this->save($run, $manifest);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.uP');
        $this->connection->transactional(function () use ($manifest, $metadata, $hash, $now, $duration): void {
            $this->connection->update('libraries', ['type' => 'movie'], ['id' => $manifest['library']['id']]);
            $this->connection->insert('movies', [
                'id' => $manifest['movie']['id'], 'public_id' => $manifest['movie']['publicId'],
                'library_id' => $manifest['library']['id'], 'title' => 'Owned playback movie ' . $manifest['run'],
                'runtime' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->connection->insert('videos', [
                'id' => $manifest['video']['id'], 'public_id' => $manifest['video']['publicId'],
                'path' => $manifest['video']['path'], 'hash' => $hash, 'duration' => $duration,
                'height' => $metadata->height, 'width' => $metadata->width, 'video_bitrate' => $metadata->videoBitrate,
                'framerate' => 24, 'probe' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->connection->insert('movie_video', [
                'id' => $manifest['movieVideoId'], 'movie_id' => $manifest['movie']['id'],
                'video_id' => $manifest['video']['id'], 'sort_order' => 0,
            ]);
        });
        if ($directors) {
            $manifest = $this->seedDirectors($run, $manifest);
            $manifest = $this->seedCrossMediaCredits($run, $manifest);
        }
        if ($departure) {
            $manifest['successorRun'] = $run . '-next';
            $this->save($run, $manifest);
            $manifest['successor'] = $this->create(
                $manifest['successorRun'],
                'encoded',
                cinema: false,
                quality: false,
                directors: false,
                scope: $manifest,
            );
            $this->save($run, $manifest);
        }
        return $manifest;
    }

    /** @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function seedCrossMediaCredits(string $run, array $manifest): array
    {
        $credits = [];
        foreach (['series', 'season', 'episode', 'artist'] as $kind) {
            $credits[$kind] = ['id' => (string) new Uuid(), 'public_id' => (string) new PublicId()];
        }
        $manifest['crossMediaCredits'] = $credits;
        $this->save($run, $manifest);

        $this->connection->transactional(function () use ($credits, $manifest, $run): void {
            $person = $this->connection->fetchOne('SELECT id FROM people WHERE public_id = ?', [$manifest['directors'][50]['publicId']]);
            if (!is_string($person)) {
                throw new RuntimeException('The director for the cross-media fixture is missing.');
            }
            $this->connection->insert('tv_series', $credits['series'] + [
                'library_id' => $manifest['library']['id'], 'tmdb_id' => 1, 'name' => 'Owned series ' . $run,
            ]);
            $this->connection->insert('tv_seasons', $credits['season'] + [
                'series_id' => $credits['series']['id'], 'season_number' => 1,
            ]);
            $this->connection->insert('tv_episodes', $credits['episode'] + [
                'season_id' => $credits['season']['id'], 'episode_number' => 1, 'tmdb_id' => 2, 'name' => 'Owned episode ' . $run,
            ]);
            foreach (['series', 'episode'] as $kind) {
                $this->connection->insert($kind . '_person_credits', [
                    'id' => (string) new Uuid(), $kind . '_id' => $credits[$kind]['id'], 'person_id' => $person,
                    'category' => 'cast', 'role' => 'actor', 'job' => 'Actor', 'character_name' => 'Guest', 'credit_key' => 'owned-guest',
                ]);
            }
            $mbid = (string) new Uuid();
            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.uP');
            $this->connection->insert('artists', $credits['artist'] + [
                'name' => 'Owned artist ' . $run, 'type' => 'Person', 'mbid' => $mbid, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->connection->insert('person_artist_links', [
                'artist_id' => $credits['artist']['id'], 'person_id' => $person, 'artist_mbid' => $mbid,
            ]);
            foreach (['song' => 'composer', 'album' => 'primary'] as $kind => $role) {
                $this->connection->insert('artist_' . $kind, [
                    'id' => (string) new Uuid(), $kind . '_id' => $manifest[$kind]['id'], 'artist_id' => $credits['artist']['id'],
                    'role' => $role, 'credited_name' => 'Owned stage name',
                ]);
            }
        });

        return $manifest;
    }

    /** @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function seedDirectors(string $run, array $manifest): array
    {
        $manifest['directors'] = [];
        $firstPersonId = random_int(1_500_000_000, 1_899_999_949);
        for ($number = 1; $number <= 51; ++$number) {
            $manifest['directors'][] = [
                'tmdbId' => $firstPersonId + $number,
                'name' => sprintf('Owned director %02d %s', $number, $run),
            ];
        }
        $this->save($run, $manifest);
        $kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', false);
        $kernel->boot();
        try {
            $manager = $kernel->getContainer()->get('doctrine')->getManager();
            $directors = new DirectorService(
                new DirectorRepository($manager),
                new MovieRepository($manager),
            );
            $credits = array_map(static fn(array $person): DirectorCredit => new DirectorCredit(
                $person['tmdbId'],
                $person['name'],
            ), $manifest['directors']);
            $manager->getConnection()->transactional(static function () use ($manager, $directors, $manifest, $credits): void {
                $movieId = Uuid::fromString($manifest['movie']['id']);
                $connection = $manager->getConnection();
                PersonWriteLock::shared($connection);
                $keys = ['person-work:movie:' . $movieId->toString()];
                $personIds = [];
                foreach ($credits as $credit) {
                    $keys[] = 'person-external:tmdb_person:' . $credit->tmdbId;
                    $personIds[] = (string) $credit->tmdbId;
                }
                ImportIdentityLock::acquire($connection, $keys);
                $existing = $connection->fetchOne(
                    "SELECT 1 FROM person_external_ids WHERE namespace = 'tmdb_person' AND external_id IN (:ids) LIMIT 1",
                    ['ids' => $personIds],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
                if ($existing !== false) {
                    throw new RuntimeException('Director fixture identity collision; existing identities were not changed.');
                }
                $directors->syncMovieDirectors($movieId, $credits, true);
            });
            $result = $directors->searchVisible(
                SearchOptions::create($run, 100),
                LibraryReadScope::unrestricted(),
            );
            $manifest['directors'] = array_map(static fn(DirectorReadView $person): array => [
                'publicId' => $person->publicId->toString(),
                'tmdbId' => $person->tmdbId,
                'name' => $person->name,
            ], $result->getItems());
            $this->save($run, $manifest);
        } finally {
            $kernel->shutdown();
        }
        return $manifest;
    }

    private function generateSource(string $run, string $path, string $delivery, string $extension, bool $quality, int $duration): void
    {
        $process = new Process([
            '/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-filter_threads', '1',
            '-f', 'lavfi', '-i', 'color=c=black:size=' . ($quality ? '1280x720' : '320x180') . ':rate=24',
            '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo', '-t', (string) $duration,
            '-c:v', $delivery === 'encoded' ? 'mpeg4' : 'libx264', '-threads', '1',
            ...($delivery === 'encoded' ? [] : ['-preset', 'ultrafast']),
            '-pix_fmt', 'yuv420p',
            '-g', '24', '-c:a', 'aac',
            ...($extension === 'mp4' ? ['-movflags', '+faststart'] : []),
            // Tag frames in the filter graph: FFmpeg 7 ignores encoder-side colour options.
            ...($delivery === 'encoded' ? [] : [
                '-vf',
                'setparams=colorspace=bt709:color_primaries=bt709:color_trc=bt709',
            ]),
            '-metadata', 'title=Owned playback ' . $run,
            $path,
        ], timeout: 60);
        $process->mustRun();
    }

    private function generateCinemaSource(string $run, string $path, bool $quality): void
    {
        $subtitles = $this->root . '/' . $run . '/cinema.srt';
        $chapters = $this->root . '/' . $run . '/cinema.ffmetadata';
        try {
            $this->writeSourceInput($subtitles, <<<'SRT'
                1
                00:00:00,000 --> 00:00:09,500
                Opening caption

                2
                00:00:10,000 --> 00:00:19,500
                Middle caption

                3
                00:00:20,000 --> 00:00:29,500
                Final caption

                SRT);

            $this->writeSourceInput($chapters, <<<'METADATA'
                ;FFMETADATA1
                [CHAPTER]
                TIMEBASE=1/1000
                START=0
                END=10000
                title=Opening
                [CHAPTER]
                TIMEBASE=1/1000
                START=10000
                END=20000
                title=Middle
                [CHAPTER]
                TIMEBASE=1/1000
                START=20000
                END=30000
                title=Finale

                METADATA);

            $process = new Process([
                '/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-filter_threads', '1',
                '-f', 'lavfi', '-i', 'testsrc2=size=' . ($quality ? '1280x720' : '320x180') . ':rate=24',
                '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
                '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000',
                '-i', $subtitles, '-f', 'ffmetadata', '-i', $chapters,
                '-map', '0:v:0', '-map', '1:a:0', '-map', '2:a:0', '-map', '3:s:0',
                '-map_metadata', '4', '-map_chapters', '4', '-t', '30',
                '-vf', "drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf:text='%{pts\\:hms}':x=10:y=10:fontsize=24:fontcolor=white:box=1:boxcolor=black",
                '-c:v', 'mpeg4', '-threads', '1', '-pix_fmt', 'yuv420p', '-g', '24',
                '-c:a', 'aac', '-c:s', 'srt',
                '-metadata:s:a:0', 'language=eng', '-metadata:s:a:0', 'title=English tone',
                '-metadata:s:a:1', 'language=dan', '-metadata:s:a:1', 'title=Danish tone',
                '-disposition:a:0', 'default', '-disposition:a:1', '0',
                '-metadata:s:s:0', 'language=eng', '-metadata:s:s:0', 'title=English captions',
                '-metadata', 'title=Owned playback ' . $run, $path,
            ], timeout: 60);
            $process->mustRun();
        } finally {
            foreach ([$subtitles, $chapters] as $input) {
                if (is_file($input) && !unlink($input)) {
                    throw new RuntimeException('Cannot remove an owned cinema source input.');
                }
            }
        }
    }

    private function writeSourceInput(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new RuntimeException('Cannot write an owned cinema source input.');
        }
    }

    /** @return array<string, mixed> */
    private function inspect(string $run): array
    {
        $manifest = json_decode(
            (string) file_get_contents($this->root . '/' . $run . '/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $video = $manifest['video'] ?? throw new RuntimeException('The fixture has no video identity.');
        return [
            'sampledAt' => (new DateTimeImmutable())->format(DateTimeInterface::RFC3339_EXTENDED),
            'sourceExists' => is_file($video['path']),
            'jobs' => $this->connection->fetchAllAssociative(<<<'SQL'
SELECT j.id, j.quality_tier_name, j.status, j.total_segments, j.completed_segments, j.fail_reason,
       r.outcome, r.desired->>'status' AS desired_status, r.desired->>'position' AS desired_position,
       r.generation, r.desired_revision, r.observed_revision, r.admission_deadline,
       s.public_id AS session_public_id,
       r.claim_token IS NOT NULL AS has_owner,
       r.claim_token IS NOT NULL AND r.lease_expires_at > clock_timestamp() AS owner_live,
       r.lease_expires_at
FROM transcode_jobs j LEFT JOIN transcode_runtime_state r ON r.job_id = j.id
LEFT JOIN transcode_sessions s ON s.id = r.session_id
WHERE j.video_id = :video ORDER BY j.created_at
SQL, ['video' => $video['id']]),
        ];
    }

    /** @return array<string, mixed> */
    private function cleanup(string $run): array
    {
        $manifest = json_decode(
            (string) file_get_contents($this->root . '/' . $run . '/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (isset($manifest['successorRun'])) {
            $successorManifest = $this->root . '/' . $manifest['successorRun'] . '/manifest.json';
            if (is_file($successorManifest)) {
                $cleanup = $this->cleanup($manifest['successorRun']);
                if (($cleanup['complete'] ?? false) !== true) {
                    return $cleanup;
                }
            }
        }
        $mediaDirectory = $this->movieMediaDirectory($run, $manifest['parentRun'] ?? null);
        if (isset($manifest['video'])) {
            $path = $manifest['video']['path'];
            $basePath = $mediaDirectory . '/owned.';
            if (!in_array($path, [$basePath . 'mp4', $basePath . 'mkv'], true)) {
                throw new RuntimeException('Video ownership path does not match its fixture scope.');
            }
            if (isset($manifest['previewIdentity'])) {
                VideoTimelinePreview::removeCachedSource(
                    $this->convertRoot . '/timeline-previews',
                    $manifest['previewIdentity'],
                );
            }
            $videoId = $manifest['video']['id'];
            $this->connection->executeStatement(<<<'SQL'
UPDATE transcode_runtime_state r
SET desired = jsonb_set(r.desired, '{status}', '"cancelled"'::jsonb),
    desired_revision = r.desired_revision + 1, updated_at = clock_timestamp()
FROM transcode_jobs j WHERE r.job_id = j.id AND j.video_id = :video
SQL, ['video' => $videoId]);
            $deadline = microtime(true) + 45;
            do {
                $owners = (int) $this->connection->fetchOne(<<<'SQL'
SELECT count(*) FROM transcode_runtime_state r JOIN transcode_jobs j ON j.id = r.job_id
WHERE j.video_id = :video AND r.claim_token IS NOT NULL AND r.lease_expires_at > clock_timestamp()
SQL, ['video' => $videoId]);
                if ($owners === 0) {
                    break;
                }
                usleep(100_000);
            } while (microtime(true) < $deadline);
            if ($owners > 0) {
                return ['complete' => false, 'manifestRetained' => true, 'ownedWorkers' => $owners];
            }
            $this->connection->transactional(function () use ($manifest, $videoId): void {
                $this->connection->fetchOne('SELECT id FROM videos WHERE id = ? FOR UPDATE', [$videoId]);
                $this->connection->fetchFirstColumn(
                    'SELECT r.job_id FROM transcode_runtime_state r JOIN transcode_jobs j ON j.id = r.job_id WHERE j.video_id = ? FOR UPDATE OF r',
                    [$videoId],
                );
                $live = (int) $this->connection->fetchOne(
                    'SELECT count(*) FROM transcode_runtime_state r JOIN transcode_jobs j ON j.id = r.job_id WHERE j.video_id = ? AND r.claim_token IS NOT NULL AND r.lease_expires_at > clock_timestamp()',
                    [$videoId],
                );
                if ($live > 0) {
                    throw new RuntimeException('An owned encoder reclaimed work during cleanup; manifest retained.');
                }
                $this->connection->delete('movie_video', ['id' => $manifest['movieVideoId']]);
                $this->connection->delete('movies', ['id' => $manifest['movie']['id']]);
                if (isset($manifest['crossMediaCredits'])) {
                    $this->connection->delete('tv_series', ['id' => $manifest['crossMediaCredits']['series']['id']]);
                    $this->connection->delete('artists', ['id' => $manifest['crossMediaCredits']['artist']['id']]);
                }
                foreach ($manifest['directors'] ?? [] as $director) {
                    $this->connection->executeStatement(
                        <<<'SQL'
                            DELETE FROM people
                            WHERE public_id = :id AND name = :name
                              AND NOT EXISTS (SELECT 1 FROM movie_person_credits WHERE person_id = people.id)
                              AND NOT EXISTS (SELECT 1 FROM series_person_credits WHERE person_id = people.id)
                              AND NOT EXISTS (SELECT 1 FROM episode_person_credits WHERE person_id = people.id)
                              AND NOT EXISTS (SELECT 1 FROM person_artist_links WHERE person_id = people.id)
                            SQL,
                        ['id' => $director['publicId'], 'name' => $director['name']],
                    );
                }
                $this->connection->delete('videos', ['id' => $videoId]);
            });
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Cannot remove the owned video; manifest retained.');
            }
            if (Uuid::fromString($videoId)->toString() !== $videoId) {
                throw new RuntimeException('Invalid owned video cache identity.');
            }
            $this->removeDirectory($this->convertRoot . '/' . $videoId);
        }
        foreach (['cinema.srt', 'cinema.ffmetadata'] as $name) {
            $input = $this->root . '/' . $run . '/' . $name;
            if (is_file($input) && !unlink($input)) {
                throw new RuntimeException('Cannot remove an owned cinema source input.');
            }
        }
        if (isset($manifest['parentRun'])) {
            $this->removeDirectory($mediaDirectory);
            $this->removeDirectory($this->root . '/' . $run);
            return ['complete' => true, 'survivors' => 0];
        }
        return $this->base->execute(['op' => 'cleanup', 'run' => $run, 'waitMs' => 30_000]);
    }

    private function movieMediaDirectory(string $run, ?string $parentRun): string
    {
        if ($parentRun === null) {
            return $this->mediaRoot . '/' . $run;
        }
        if ($run !== $parentRun . '-next') {
            throw new RuntimeException('Successor media must be within its fixture library.');
        }
        return $this->mediaRoot . '/' . $parentRun . '/' . $run;
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Refusing a linked video cache directory.');
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $child = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectory($child);
            } elseif (!unlink($child)) {
                throw new RuntimeException('Cannot remove an owned cache file.');
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Cannot remove an owned cache directory.');
        }
    }

    /** @param array<string, mixed> $manifest */
    private function save(string $run, array $manifest): void
    {
        $path = $this->root . '/' . $run . '/manifest.json';
        $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (
            file_put_contents($path . '.tmp', $json) !== strlen($json)
            || !chmod($path . '.tmp', 0600) || !rename($path . '.tmp', $path)
        ) {
            throw new RuntimeException('Cannot persist video fixture ownership.');
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    (new Dotenv())->bootEnv(dirname(__DIR__, 3) . '/.env');
    $environment = static function (string $key): string {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if (!is_string($value) || !str_starts_with($value, '/')) {
            throw new RuntimeException($key . ' must be an absolute configured path.');
        }
        return rtrim($value, '/');
    };
    $root = dirname(__DIR__, 3) . '/var/playback-video';
    $mediaRoot = $environment('MEDIA_STORAGE_PATH') . '/.playback-video';
    $convertRoot = $environment('CONVERT_STORAGE_PATH');
    $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
    $connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse((string) $url));
    $connection->executeStatement("SET TIME ZONE 'UTC'");
    $redis = $_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? getenv('REDIS_URL');
    $base = new LiveCapacityFixture($connection, $root, $mediaRoot, new RedisClientFactory((string) $redis));
    $fixture = new PlaybackVideoFixture($connection, $base, $root, $mediaRoot, $convertRoot);
    try {
        while (($line = fgets(STDIN)) !== false) {
            $command = [];
            try {
                $command = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $receipt = $fixture->execute($command);
                $response = ['requestId' => $command['requestId'] ?? null, 'op' => $command['op'] ?? null,
                    'run' => $receipt['run'] ?? $command['run'] ?? null, 'ok' => true, 'complete' => true, 'receipt' => $receipt];
            } catch (Throwable $error) {
                $response = ['requestId' => $command['requestId'] ?? null, 'op' => $command['op'] ?? null,
                    'run' => $command['run'] ?? null, 'ok' => false, 'complete' => true, 'error' => $error->getMessage()];
            }
            fwrite(STDOUT, json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            fflush(STDOUT);
        }
    } finally {
        $base->close();
    }
}
