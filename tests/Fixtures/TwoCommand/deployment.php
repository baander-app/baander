<?php

declare(strict_types=1);

use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\WebRuntime\HttpExchange;
use App\Tests\Fixtures\WebRuntime\StreamClient;
use Symfony\Component\Process\Process;

$project = dirname(__DIR__, 3);
require $project . '/vendor/autoload.php';
// Production Composer intentionally excludes the development namespace.
spl_autoload_register(static function (string $class) use ($project): void {
    $prefix = 'App\\Tests\\';
    if (str_starts_with($class, $prefix)) {
        $path = $project . '/tests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($project . '/.env');
const BASE = 'http://127.0.0.1:9501';
const ROOT = '/var/www/html/storage/two-command';
const STATE = ROOT . '/state.json';
const EMAIL = 'two-command-admin@baander.app';
const VIDEO = '/media/source/video.mp4';
const AUDIO = '/media/source/track.flac';

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
/** @return array<string,mixed> */
function state(): array
{
    return json_decode((string) file_get_contents(STATE), true, flags: JSON_THROW_ON_ERROR);
}
/** @param array<string,mixed> $value */
function save(array $value): void
{
    file_put_contents(STATE, json_encode($value, JSON_THROW_ON_ERROR));
    chmod(STATE, 0600);
}
/** @param list<string> $arguments */
function command(array $arguments, float $timeout = 90): void
{
    $process = new Process($arguments);
    $process->setTimeout($timeout);
    $process->mustRun();
}
/** @return array<string,mixed> */
function jsonBody(HttpExchange $response): array
{
    return json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
}
/** @param array<string, string|int|float|bool>|null $payload */
function twoCommandExchange(StreamClient $client, string $path, string $method = 'GET', ?array $payload = null): HttpExchange
{
    $endpoint = BASE . $path;
    $proofEndpoint = explode('?', $endpoint, 2)[0];
    return new HttpExchange($endpoint, [...$client->proofHeaders($method, $proofEndpoint), 'Accept: application/json', 'Content-Type: application/json'], $method,
        $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));
}
function await(callable $probe, string $description, float $seconds = 30): mixed
{
    $deadline = microtime(true) + $seconds;
    do {
        clearstatcache();
        $value = $probe();
        if ($value !== false && $value !== null) {
            return $value;
        }
        usleep(50_000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Timed out: ' . $description);
}
/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function row(Doctrine\DBAL\Connection $db, array $state): array
{
    $row = $db->fetchAssociative('SELECT r.*, clock_timestamp() AS sampled_at, j.status AS job_status, s.public_id AS session_public_id FROM transcode_runtime_state r JOIN transcode_sessions s ON s.id=r.session_id JOIN transcode_jobs j ON j.id=r.job_id WHERE j.public_id = ?', [$state['job']]);
    ensure($row !== false, 'Durable runtime disappeared.');
    $row['desired'] = json_decode($row['desired'], true, flags: JSON_THROW_ON_ERROR);
    return $row;
}
/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function acknowledged(Doctrine\DBAL\Connection $db, array $state, string $status, ?float $position = null): array
{
    $last = [];
    try {
        return await(static function () use ($db, $state, $status, $position, &$last): array|false {
            $last = row($db, $state);
            return $last['desired']['status'] === $status && (int) $last['observed_revision'] >= (int) $last['desired_revision']
                && ($position === null || abs((float) $last['desired']['position'] - $position) < .01) ? $last : false;
        }, 'worker acknowledges ' . $status);
    } catch (RuntimeException $error) {
        $diagnostic = array_intersect_key($last, array_flip([
            'sampled_at', 'generation', 'desired_revision', 'observed_revision', 'outcome',
            'worker_boot_id', 'worker_lease_epoch', 'lease_expires_at', 'job_status',
        ]));
        $diagnostic['desiredStatus'] = $last['desired']['status'] ?? null;
        $diagnostic['desiredPosition'] = $last['desired']['position'] ?? null;
        $diagnostic['hasClaim'] = ($last['claim_token'] ?? null) !== null;
        $diagnostic['hasOutput'] = ($last['output_directory'] ?? null) !== null;
        fwrite(STDERR, json_encode(['acknowledgement' => $diagnostic], JSON_THROW_ON_ERROR) . "\n");
        throw $error;
    }
}
/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function playback(StreamClient $client, array $state): array
{
    $response = twoCommandExchange($client, '/api/transcode/sessions/' . $state['session'])->complete();
    ensure($response->status() === 200, 'Session read failed: ' . $response->body);
    $playback = jsonBody($response)['data']['playback'] ?? null;
    ensure(is_array($playback), 'Session omitted current playback.');
    return $playback;
}
function url(string $path): string
{
    return str_starts_with($path, '/') ? BASE . $path : $path;
}
/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function decodeVideo(StreamClient $client, array $state): array
{
    $playback = await(static function () use ($client, $state): array|false {
        $value = playback($client, $state);
        return ($value['ready'] ?? false) ? $value : false;
    }, 'worker publishes current generation', 45);
    $master = (new HttpExchange(url($playback['manifestUrl']), []))->complete();
    ensure($master->status() === 200, 'Master playlist is unavailable: ' . $master->body);
    preg_match('~^([^#\r\n][^\r\n]*)~m', $master->body, $mediaMatch);
    ensure(isset($mediaMatch[1]), 'Master playlist has no rendition.');
    $mediaUrl = url($mediaMatch[1]);
    $media = (new HttpExchange($mediaUrl, []))->complete();
    ensure($media->status() === 200, 'Media playlist is unavailable.');
    preg_match('/#EXT-X-MAP:URI="([^"]+)"/', $media->body, $initMatch);
    preg_match('~^([^#\r\n][^\r\n]*)~m', $media->body, $segmentMatch);
    ensure(isset($initMatch[1], $segmentMatch[1]), 'Playlist omitted init or fragment.');
    $init = (new HttpExchange(url($initMatch[1]), []))->complete();
    $segment = (new HttpExchange(url($segmentMatch[1]), []))->complete();
    ensure($init->status() === 200 && $segment->status() === 200, 'Worker init or fragment is unavailable.');
    $path = ROOT . '/decoded-generation-' . $playback['generation'] . '.mp4';
    file_put_contents($path, $init->body . $segment->body);
    command(['ffmpeg', '-nostdin', '-v', 'error', '-threads', '1', '-i', $path, '-map', '0:v:0', '-f', 'null', '-']);
    return $playback + ['mediaUrl' => $mediaUrl, 'segmentUrl' => url($segmentMatch[1]), 'decodedBytes' => strlen($init->body) + strlen($segment->body)];
}
$mode = $argv[1] ?? '';
$started = microtime(true);
set_exception_handler(static function (Throwable $error) use ($mode): never {
    fwrite(STDERR, 'two-command ' . $mode . ': ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
});
$kernel = new App\Kernel('prod', false);
$kernel->boot();
$manager = $kernel->getContainer()->get('doctrine')->getManager();
$db = $manager->getConnection();
$client = static fn (): StreamClient => new StreamClient($manager, EMAIL,
    (string) file_get_contents($project . '/config/secrets/oauth/private.key'), BASE . '/api/stream/track');
$result = [];
if ($mode === 'prepare') {
    ensure(is_dir(ROOT) || mkdir(ROOT, 0750, true), 'Cannot create scratch state.');
    command(['ffmpeg', '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=1280x720:rate=30:duration=180', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=180', '-c:v', 'libx264', '-preset', 'ultrafast', '-threads', '2', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', VIDEO], 150);
    command(['ffmpeg', '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1800', '-ac', '2', '-ar', '44100', '-threads', '1', '-c:a', 'flac', AUDIO], 60);
    $library = new LibraryEntity('Two command music', 'two-command-music', '/media/source', 'music', 'local');
    $movies = new LibraryEntity('Two command movies', 'two-command-movies', '/media/source', 'movie', 'local');
    $album = new AlbumEntity(new PublicId(), $library, 'Two command album', 'two-command-album');
    $song = new SongEntity(new PublicId(), $album, 'Worker audio', 'track.flac', (int) filesize(AUDIO), 'audio/flac');
    $video = new VideoEntity(new PublicId(), VIDEO, hash_file('sha256', VIDEO));
    $video->setDuration(180); $video->setWidth(1280); $video->setHeight(720); $video->setFramerate(30);
    $movie = new MovieEntity(new PublicId(), $movies, 'Two command playback');
    $movieVideo = new MovieVideoEntity($movie, $video);
    $imageRoot = ROOT;
    ensure(is_dir($imageRoot) || mkdir($imageRoot, 0755, true), 'Cannot create image scratch.');
    $imageData = imagecreatetruecolor(640, 480);
    ensure($imageData !== false && imagepng($imageData, $imageRoot . '/cover.png'), 'Cannot generate PNG.');
    $image = new ImageEntity('two-command/cover.png', 'png', 'image/png', new PublicId(), (int) filesize($imageRoot . '/cover.png'), 640, 480, 'album');
    $image->setAlbum($album);
    foreach ([$library, $movies, $album, $song, $video, $movie, $movieVideo, $image] as $entity) { $manager->persist($entity); }
    $manager->flush();
    save(['video' => (string) $video->getPublicId(), 'movie' => (string) $movie->getPublicId(), 'track' => (string) $song->getPublicId(), 'image' => (string) $image->getPublicId()]);
    $result = state();
} elseif ($mode === 'absent') {
    $state = state(); $auth = $client();
    $video = twoCommandExchange($auth, '/api/transcode/sessions/', 'POST', ['videoId' => $state['video'], 'qualityTier' => '360p']);
    $video->complete(15);
    ensure($video->status() === 503 && $video->header('Retry-After') === '2', 'Missing worker did not refuse video within its bound.');
    // Its sixty-second application deadline needs a client deadline above sixty.
    $endpoint = BASE . '/api/stream/track?id=' . $state['track'] . '&format=mp3&bitrate=64000';
    $handle = curl_init($endpoint);
    curl_setopt_array($handle, [CURLOPT_HTTPHEADER => $auth->proofHeaders('GET', BASE . '/api/stream/track'), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 75]);
    $body = curl_exec($handle); $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    ensure($body !== false && $status >= 500, 'Missing worker did not refuse audio within its bound.');
    curl_close($handle);
    $direct = $auth->open('id=' . $state['track'] . '', ['Range: bytes=0-127'])->complete();
    ensure(in_array($direct->status(), [200, 206], true) && strlen($direct->body) > 0, 'Direct delivery failed without a worker.');
    ensure((new HttpExchange(BASE . '/live', []))->complete()->status() === 200, 'Web liveness failed without a worker.');
    $result = ['videoStatus' => $video->status(), 'audioStatus' => $status, 'directStatus' => $direct->status()];
} elseif ($mode === 'capacity') {
    $state = state(); $auth = $client(); $sessions = [];
    foreach (['360p', '480p', '720p'] as $quality) {
        $response = twoCommandExchange($auth, '/api/transcode/sessions/', 'POST', ['videoId' => $state['video'], 'qualityTier' => $quality])->complete(20);
        ensure($response->status() === 201, 'Capacity video admission failed: ' . $response->body);
        $sessions[] = jsonBody($response);
    }
    ensure(count(array_unique(array_column($sessions, 'jobId'))) === 3, 'Capacity needs three distinct video jobs.');
    $audio = $auth->open('id=' . $state['track'] . '&format=mp3&bitrate=192000', timeoutSeconds: 120);
    ensure($audio->until(static fn (HttpExchange $value): bool => strlen($value->body) >= 8192, 30), 'Capacity audio did not start.');
    await(static fn (): bool => (int) $db->fetchOne("SELECT count(*) FROM transcode_runtime_state WHERE claim_token IS NOT NULL") >= 3,
        'three durable video slots');
    ensure((int) $db->fetchOne("SELECT count(*) FROM audio_rendition_runtime WHERE state='running'") >= 1, 'Capacity audio slot is not occupied.');
    require_once $project . '/tests/Fixtures/Messaging/MessageCodecFactory.php';
    $transport = new Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport(
        Symfony\Component\Messenger\Bridge\Redis\Transport\Connection::fromDsn('redis://default:test-only@redis:6379/messages/baander'),
        new App\Shared\Infrastructure\Messenger\JsonTransportSerializer(App\Tests\Fixtures\Messaging\MessageCodecFactory::create()),
    );
    $redis = new Redis(); $redis->connect('redis', 6379, 1); $redis->auth(['default', 'test-only']);
    $sent = 0;
    $feed = static function () use ($transport, $redis, &$sent): void {
        $needed = max(0, 2000 - $redis->xLen('messages'));
        for ($index = 0; $index < $needed; ++$index) {
            $transport->send(new Symfony\Component\Messenger\Envelope(new App\Shared\Domain\Event\Outbox\RelayOutboxCommand()));
            ++$sent;
        }
    };
    $feed();
    $schedulerJob = $db->fetchOne("SELECT id FROM scheduled_jobs WHERE command='app:transcode:cache-sweep'");
    ensure(is_string($schedulerJob), 'Capacity scheduler fixture missing.');
    $recorder = App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerManualOccurrenceRecorder::fromDsn((string) getenv('DATABASE_URL'));
    $occurrences = []; $nextOccurrence = 0.0; $backlog = [];
    file_put_contents(ROOT . '/capacity-ready', 'ready');
    await(static fn (): bool => is_file(ROOT . '/capacity-pressure-start'), 'capacity pressure barrier', 60);
    $samples = []; $deadline = hrtime(true) + 60_000_000_000; $paused = false;
    while (hrtime(true) < $deadline) {
        $feed();
        $depth = $redis->xLen('messages');
        $backlog[] = ['monotonic' => hrtime(true) / 1e9, 'receiverDepth' => $depth, 'sent' => $sent, 'acknowledged' => $sent - $depth,
            'relayedEvents' => (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox WHERE relayed_at IS NOT NULL')];
        if (hrtime(true) / 1e9 >= $nextOccurrence && hrtime(true) < $deadline - 10_000_000_000) {
            $request = App\Shared\Domain\Model\Uuid::v7();
            ensure($recorder->record(App\Shared\Domain\Model\Uuid::fromString($schedulerJob), $request) !== null, 'Due occurrence was not recorded.');
            $occurrences[] = ['id' => $request->toString(), 'recordedAt' => hrtime(true) / 1e9];
            $nextOccurrence = hrtime(true) / 1e9 + 10;
        }
        $paused = !$paused;
        $startedAt = hrtime(true);
        $session = $sessions[0];
        $response = twoCommandExchange($auth, '/api/transcode/sessions/' . $session['publicId'] . ($paused ? '/pause' : '/resume'), 'PATCH')->complete(3);
        ensure($response->status() === 200, 'Desired state request failed during pressure.');
        $observed = acknowledged($db, ['job' => $session['jobId']], $paused ? 'paused' : 'running');
        $samples[] = ['seconds' => (hrtime(true) - $startedAt) / 1e9, 'desiredRevision' => $observed['desired_revision'], 'observedRevision' => $observed['observed_revision']];
        // Feed real ordinary outbox work, with disabled outbound preferences.
        $user = $manager->getRepository(App\Auth\Infrastructure\Doctrine\Entity\UserEntity::class)->findOneBy(['email' => 'worker-command@baander.app']);
        ensure($user !== null, 'Capacity backlog fixture user missing.');
        $kernel->getContainer()->get('event_dispatcher')->dispatch(new App\Auth\Domain\Event\UserRegistered($user->getId(), $user->getPublicId(), App\Shared\Domain\Model\Email::fromString($user->getEmail()), $user->getName()));
        $audio->until(static fn (HttpExchange $value): bool => $value->done, .01);
        usleep(100000);
    }
    foreach ($occurrences as &$occurrence) {
        $occurrence['execution'] = $db->fetchAssociative('SELECT e.deployment_namespace, e.deployment_boot_id, e.deployment_epoch, EXTRACT(EPOCH FROM (e.returned_at - o.created_at)) AS completion_seconds, e.returned_at IS NOT NULL AS returned FROM scheduler_occurrence_executions e JOIN scheduler_occurrences o ON o.id=e.occurrence_id WHERE o.id=?', [$occurrence['id']]);
    }
    unset($occurrence);
    $result = ['ordinaryBacklog' => $backlog, 'dueOccurrences' => $occurrences, 'videoJobs' => array_column($sessions, 'jobId'), 'desiredStateSamples' => $samples, 'audioBytes' => strlen($audio->body), 'pressureStartedAt' => ($deadline - 60_000_000_000) / 1e9, 'pressureEndedAt' => hrtime(true) / 1e9];
} elseif ($mode === 'attach') {
    $state = state(); $auth = $client();
    $requests = [];
    foreach (range(1, 4) as $_) {
        $requests[] = twoCommandExchange($auth, '/api/transcode/sessions/', 'POST', ['videoId' => $state['video'], 'qualityTier' => '360p']);
    }
    $deadline = microtime(true) + 20;
    do {
        $done = true;
        foreach ($requests as $request) {
            if (!$request->done) { $request->until(static fn (HttpExchange $value): bool => $value->done, .05); }
            $done = $done && $request->done;
        }
    } while (!$done && microtime(true) < $deadline);
    $sessions = [];
    foreach ($requests as $request) {
        ensure($request->done && $request->status() === 201, 'Concurrent attachment failed: ' . $request->body);
        $body = jsonBody($request); $sessions[] = $body['publicId']; $state['job'] = $body['jobId'];
    }
    ensure(count(array_unique($sessions)) === 1, 'Concurrent web attachments did not converge.');
    $state['session'] = $sessions[0]; save($state);
    ensure((int) $db->fetchOne('SELECT count(*) FROM transcode_runtime_state r JOIN transcode_jobs j ON j.id=r.job_id WHERE j.public_id=?', [$state['job']]) === 1, 'Duplicate durable video owner.');
    $decoded = decodeVideo($auth, $state); $state['oldSegmentUrl'] = $decoded['segmentUrl']; save($state);
    $audio = $auth->open('id=' . $state['track'] . '&format=mp3&bitrate=192000');
    ensure($audio->until(static fn (HttpExchange $value): bool => strlen($value->body) >= 8192, 30), 'Worker did not produce progressive audio.');
    $audioRow = $db->fetchAssociative("SELECT state, worker_boot_id FROM audio_rendition_runtime WHERE bitrate=192000");
    ensure($audioRow !== false && $audioRow['state'] === 'running' && !$audio->done, 'Audio bytes were not received while the worker encoder was running.');
    $firstBytes = strlen($audio->body);
    ensure($audio->until(static fn (HttpExchange $value): bool => strlen($value->body) > $firstBytes + 8192, 30), 'Worker audio did not grow.');
    $audio->complete(55);
    file_put_contents(ROOT . '/decoded.mp3', $audio->body);
    command(['ffmpeg', '-nostdin', '-v', 'error', '-threads', '1', '-i', ROOT . '/decoded.mp3', '-f', 'null', '-']);
    $image = twoCommandExchange($auth, '/api/images/' . $state['image'] . '/file?preset=thumb')->complete();
    ensure($image->status() === 200 && $image->header('Content-Type') === 'image/png', sprintf('Image request did not return its source while conversion was queued: HTTP %d, Content-Type %s, %d bytes.', $image->status(), $image->header('Content-Type') ?? '(missing)', strlen($image->body)));
    await(static fn (): bool => is_file(ROOT . '/cover_thumb.webp'), 'worker image conversion');
    $converted = twoCommandExchange($auth, '/api/images/' . $state['image'] . '/file?preset=thumb')->complete();
    ensure($converted->status() === 200 && $converted->header('Content-Type') === 'image/webp', sprintf('Worker image conversion is not delivered: HTTP %d, Content-Type %s, %d bytes.', $converted->status(), $converted->header('Content-Type') ?? '(missing)', strlen($converted->body)));
    $recommendation = twoCommandExchange($auth, '/api/admin/recommendations/generate', 'POST', ['mode' => 'full'])->complete();
    ensure($recommendation->status() === 202 && (jsonBody($recommendation)['data']['execution'] ?? null) === 'async', 'Recommendation admission lost its typed asynchronous response.');
    $recommendationId = jsonBody($recommendation)['data']['public_id'];
    await(static function () use ($db, $recommendationId): bool { return $db->fetchOne('SELECT status FROM recommendation_jobs WHERE public_id=?', [$recommendationId]) === 'completed'; }, 'worker recommendation completion', 45);
    $result = ['attachments' => count($requests), 'uniqueSessions' => count(array_unique($sessions)), 'video' => $decoded, 'audioBytes' => strlen($audio->body), 'audioWorkerBoot' => $audioRow['worker_boot_id'], 'imageStatus' => $converted->status(), 'recommendationStatus' => $recommendation->status()];
} elseif (in_array($mode, ['seek-paused', 'resume', 'pause-absent', 'cancel'], true)) {
    $state = state(); $auth = $client();
    if ($mode === 'seek-paused' || $mode === 'pause-absent') {
        $pause = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'] . '/pause', 'PATCH')->complete();
        ensure($pause->status() === 200, 'Pause failed: ' . $pause->body);
        if ($mode === 'seek-paused') { acknowledged($db, $state, 'paused'); }
        $before = row($db, $state);
        $seek = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'] . '/position', 'POST', ['position' => 30, 'action' => 'seek'])->complete();
        ensure($seek->status() === 200, 'Seek failed: ' . $seek->body);
        $current = $mode === 'seek-paused' ? acknowledged($db, $state, 'paused', 30) : row($db, $state);
        ensure($current['desired']['status'] === 'paused' && (float) $current['desired']['position'] === 30.0, 'Durable paused seek was lost.');
        $staleGeneration = (int) $current['generation'] - 1;
        ensure($staleGeneration > 0, 'Paused seek has no earlier media generation to reject.');
        $staleSeek = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'] . '/position', 'POST', [
            'position' => 33.246624, 'action' => 'seek', 'generation' => $staleGeneration,
        ])->complete();
        ensure($staleSeek->status() === 200, 'Stale native seek was not handled.');
        $afterStaleSeek = row($db, $state);
        ensure((int) $afterStaleSeek['generation'] === (int) $current['generation']
            && (int) $afterStaleSeek['desired_revision'] === (int) $current['desired_revision']
            && $afterStaleSeek['desired'] === $current['desired'], 'Old media generation overwrote the committed paused seek.');
        $result['staleGenerationIgnored'] = true;
        if ($mode === 'seek-paused') {
            ensure((int) $current['generation'] > (int) $before['generation'], 'Seek did not advance the generation.');
            $old = (new HttpExchange($state['oldSegmentUrl'], []))->complete();
            ensure($old->status() !== 200, 'Old-generation media remained deliverable.');
            $result['oldGenerationStatus'] = $old->status();
        }
        $state['generation'] = (int) $current['generation']; save($state);
    } elseif ($mode === 'resume') {
        $resume = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'] . '/resume', 'PATCH')->complete();
        ensure($resume->status() === 200, 'Resume failed: ' . $resume->body);
        $current = acknowledged($db, $state, 'running', 30);
    } else {
        $cancel = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'], 'DELETE')->complete();
        ensure($cancel->status() === 200, 'Cancel failed: ' . $cancel->body);
        $current = await(static function () use ($db, $state): array|false { $value = row($db, $state); return $value['outcome'] === 'cancelled' && $value['claim_token'] === null ? $value : false; }, 'cancel releases media owner');
    }
    $result += ['generation' => (int) $current['generation'], 'desired' => $current['desired'], 'desiredRevision' => (int) $current['desired_revision'], 'observedRevision' => (int) $current['observed_revision'], 'outcome' => $current['outcome']];
} elseif ($mode === 'recovered') {
    $state = state(); $auth = $client();
    $current = acknowledged($db, $state, 'paused', 30);
    ensure((int) $current['generation'] >= $state['generation'], 'Recovery regressed generation.');
    $decoded = decodeVideo($auth, $state);
    ensure($decoded['status'] === 'paused' && (float) $decoded['position'] === 30.0, 'Recovered playback lost paused position.');
    $result = ['workerBoot' => $current['worker_boot_id'], 'playback' => $decoded];
} elseif ($mode === 'dependency') {
    $response = twoCommandExchange($client(), '/api/admin/recommendations/generate', 'POST', ['mode' => 'full'])->complete(15);
    ensure($response->status() >= 500, 'Unavailable queue did not refuse admission.');
    ensure((new HttpExchange(BASE . '/live', []))->complete()->status() === 200, 'Queue outage broke web liveness.');
    $result = ['queueAdmissionStatus' => $response->status()];
} else {
    throw new RuntimeException('Unknown drill phase.');
}
// Production logging also uses stdout; keep the phase receipt on its own channel.
$receipt = json_encode(['phase' => $mode, 'seconds' => round(microtime(true) - $started, 3), 'evidence' => $result], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
$receiptPath = ROOT . '/result-' . $mode . '.json';
ensure(file_put_contents($receiptPath, $receipt, LOCK_EX) === strlen($receipt), 'Cannot write phase receipt.');
ensure(chmod($receiptPath, 0600), 'Cannot protect phase receipt.');
echo $receipt;
