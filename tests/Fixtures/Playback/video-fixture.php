<?php

declare(strict_types=1);

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
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
            'create' => $this->create($run, $command['delivery'] ?? 'encoded'),
            'cleanup' => $this->cleanup($run),
            'inspect' => $this->inspect($run),
            default => throw new InvalidArgumentException('Unknown video fixture operation.'),
        };
    }

    /** @return array<string, mixed> */
    private function create(string $run, string $delivery): array
    {
        if (!in_array($delivery, ['encoded', 'original', 'copy'], true)) {
            throw new InvalidArgumentException('Unknown fixture delivery mode.');
        }
        $manifest = $this->base->execute(['op' => 'create', 'run' => $run]);
        $manifest['movie'] = ['id' => (string) new Uuid(), 'publicId' => (string) new PublicId()];
        $extension = $delivery === 'copy' ? 'mkv' : 'mp4';
        $manifest['video'] = ['id' => (string) new Uuid(), 'publicId' => (string) new PublicId(), 'path' => $this->mediaRoot . '/' . $run . '/owned.' . $extension];
        $manifest['movieVideoId'] = (string) new Uuid();
        // Persist exact ownership before creating files or rows, including failed runs.
        $this->save($run, $manifest);
        $process = new Process([
            '/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-filter_threads', '1',
            '-f', 'lavfi', '-i', 'color=c=black:size=320x180:rate=24',
            '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo', '-t', '30',
            '-c:v', $delivery === 'encoded' ? 'mpeg4' : 'libx264', '-threads', '1',
            ...($delivery === 'encoded' ? [] : ['-preset', 'ultrafast']),
            '-pix_fmt', 'yuv420p',
            '-g', '24', '-c:a', 'aac',
            ...($extension === 'mp4' ? ['-movflags', '+faststart'] : []),
            // Tag frames in the filter graph: FFmpeg 7 ignores encoder-side colour options.
            ...($delivery === 'encoded' ? [] : ['-vf', 'setparams=colorspace=bt709:color_primaries=bt709:color_trc=bt709']),
            '-metadata', 'title=Owned playback ' . $run,
            $manifest['video']['path'],
        ], timeout: 60);
        $process->mustRun();
        if (!chmod($manifest['video']['path'], 0644)) {
            throw new RuntimeException('Cannot publish the owned video to source workers.');
        }
        $probe = new Process(['/usr/bin/ffprobe', '-v', 'error', '-show_format', '-show_streams', '-of', 'json', $manifest['video']['path']], timeout: 30);
        $probe->mustRun();
        $metadata = VideoProbeResult::fromProbeOutput(json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        $hash = hash_file('xxh3', $manifest['video']['path']);
        if (!is_string($hash)) {
            throw new RuntimeException('Cannot hash the owned video.');
        }
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.uP');
        $this->connection->transactional(function () use ($manifest, $metadata, $hash, $now): void {
            $this->connection->update('libraries', ['type' => 'movie'], ['id' => $manifest['library']['id']]);
            $this->connection->insert('movies', [
                'id' => $manifest['movie']['id'], 'public_id' => $manifest['movie']['publicId'],
                'library_id' => $manifest['library']['id'], 'title' => 'Owned playback movie ' . $manifest['run'],
                'runtime' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->connection->insert('videos', [
                'id' => $manifest['video']['id'], 'public_id' => $manifest['video']['publicId'],
                'path' => $manifest['video']['path'], 'hash' => $hash, 'duration' => 30,
                'height' => $metadata->height, 'width' => $metadata->width, 'video_bitrate' => $metadata->videoBitrate,
                'framerate' => 24, 'probe' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->connection->insert('movie_video', [
                'id' => $manifest['movieVideoId'], 'movie_id' => $manifest['movie']['id'],
                'video_id' => $manifest['video']['id'], 'sort_order' => 0,
            ]);
        });
        return $manifest;
    }

    /** @return array<string, mixed> */
    private function inspect(string $run): array
    {
        $manifest = json_decode((string) file_get_contents($this->root . '/' . $run . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $video = $manifest['video'] ?? throw new RuntimeException('The fixture has no video identity.');
        return [
            'sampledAt' => (new DateTimeImmutable())->format(DateTimeInterface::RFC3339_EXTENDED),
            'sourceExists' => is_file($video['path']),
            'jobs' => $this->connection->fetchAllAssociative(<<<'SQL'
SELECT j.id, j.quality_tier_name, j.status, j.total_segments, j.completed_segments, j.fail_reason,
       r.outcome, r.desired->>'status' AS desired_status, r.desired->>'position' AS desired_position,
       r.generation, r.desired_revision, r.observed_revision, r.admission_deadline,
       r.claim_token IS NOT NULL AS has_owner,
       r.claim_token IS NOT NULL AND r.lease_expires_at > clock_timestamp() AS owner_live,
       r.lease_expires_at
FROM transcode_jobs j LEFT JOIN transcode_runtime_state r ON r.job_id = j.id
WHERE j.video_id = :video ORDER BY j.created_at
SQL, ['video' => $video['id']]),
        ];
    }

    /** @return array<string, mixed> */
    private function cleanup(string $run): array
    {
        $manifest = json_decode((string) file_get_contents($this->root . '/' . $run . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        if (isset($manifest['video'])) {
            $path = $manifest['video']['path'];
            $basePath = $this->mediaRoot . '/' . $run . '/owned.';
            if (!in_array($path, [$basePath . 'mp4', $basePath . 'mkv'], true)) {
                throw new RuntimeException('Video ownership path does not match its fixture scope.');
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
                $this->connection->fetchFirstColumn('SELECT r.job_id FROM transcode_runtime_state r JOIN transcode_jobs j ON j.id = r.job_id WHERE j.video_id = ? FOR UPDATE OF r', [$videoId]);
                $live = (int) $this->connection->fetchOne('SELECT count(*) FROM transcode_runtime_state r JOIN transcode_jobs j ON j.id = r.job_id WHERE j.video_id = ? AND r.claim_token IS NOT NULL AND r.lease_expires_at > clock_timestamp()', [$videoId]);
                if ($live > 0) {
                    throw new RuntimeException('An owned encoder reclaimed work during cleanup; manifest retained.');
                }
                $this->connection->delete('movie_video', ['id' => $manifest['movieVideoId']]);
                $this->connection->delete('movies', ['id' => $manifest['movie']['id']]);
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
        return $this->base->execute(['op' => 'cleanup', 'run' => $run, 'waitMs' => 30_000]);
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
        if (file_put_contents($path . '.tmp', $json) !== strlen($json)
            || !chmod($path . '.tmp', 0600) || !rename($path . '.tmp', $path)) {
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
