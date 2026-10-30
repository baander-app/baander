<?php

declare(strict_types=1);

use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\WebRuntime\HttpExchange;
use App\Tests\Fixtures\WebRuntime\StreamClient;

/**
 * @var string $project
 * @var Closure(): StreamClient $client
 * @var Doctrine\ORM\EntityManagerInterface $manager
 * @var Doctrine\DBAL\Connection $db
 */
require_once $project . '/tests/Fixtures/Worker/MediaControlObservation.php';

/** @return array<string,mixed> */
function mediaNativeState(): array
{
    $value = json_decode((string) file_get_contents(ROOT . '/media-native-state.json'), true, flags: JSON_THROW_ON_ERROR);
    ensure(hrtime(true) / 1e9 - $value['monotonic'] < 2, 'Native identity observer stopped publishing.');
    return $value;
}
/** @param array<string,mixed> $identity */
function mediaIdentityAlive(array $identity): bool
{
    return in_array($identity, mediaNativeState()['alive'], true);
}
function mediaJobUuid(string $publicId): string
{
    global $db;
    $id = $db->fetchOne('SELECT id FROM transcode_jobs WHERE public_id=?', [$publicId]);
    ensure(is_string($id), 'Native job identity is missing.');
    return $id;
}
/** @return array<string,mixed> */
function mediaAttempt(string $job): array
{
    return await(static function () use ($job): array|false {
        $launches = MediaControlObservation::launchesForJob(mediaJobUuid($job));
        if ($launches === []) { return false; }
        $latest = $launches[array_key_last($launches)];
        return mediaIdentityAlive($latest['identity']) ? $latest : false;
    }, 'live native attempt');
}
/** @param array<string,mixed> $payload */
function mediaSocket(StreamClient $auth, array $payload): void
{
    $ticket = $auth->webSocketTicket(BASE . '/api/ws/tickets', 'https://baander.app');
    $failure = null;
    Swoole\Coroutine\run(static function () use ($ticket, $payload, &$failure): void {
        $socket = new Swoole\Coroutine\Http\Client('127.0.0.1', 9501);
        try {
            $socket->set(['timeout' => 2]);
            $socket->setHeaders(['Origin' => 'https://baander.app']);
            ensure($socket->upgrade('/api/ws?ticket=' . $ticket), 'Ticketed control socket upgrade failed.');
            ensure($socket->push(json_encode(['type' => 'transcode.position', ...$payload], JSON_THROW_ON_ERROR)), 'Socket control send failed.');
            $deadline = microtime(true) + 2;
            do {
                $frame = $socket->recv(max(.01, $deadline - microtime(true)));
                ensure($frame instanceof Swoole\WebSocket\Frame, 'Control socket closed before acknowledgement.');
                $message = json_decode($frame->data, true, flags: JSON_THROW_ON_ERROR);
                if (($message['type'] ?? '') === 'transcode.position_ack') {
                    ensure(($message['requestId'] ?? null) === $payload['requestId'] && $message['status'] === 'processed', 'Socket omitted handling receipt identity.');
                    return;
                }
                ensure(($message['type'] ?? '') !== 'error', 'Socket rejected control.');
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Socket acknowledgement exceeded two seconds.');
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            $socket->close();
        }
    });
    if ($failure !== null) { throw $failure; }
}

$state = state(); $auth = $client(); $sessions = [];
$owners = ['360p' => $auth];
foreach (['480p', '720p', '1080p'] as $quality) {
    $email = 'media-control-' . $quality . '@baander.app';
    $owner = new App\Auth\Infrastructure\Doctrine\Entity\UserEntity(
        new App\Shared\Domain\Model\PublicId(), 'Media control ' . $quality, $email, 'unused-password', '', roles: ['ROLE_ADMIN'],
    );
    $owner->markEmailAsVerified();
    $manager->persist($owner);
    $manager->flush();
    $owners[$quality] = new StreamClient($manager, $email,
        (string) file_get_contents($project . '/config/secrets/oauth/private.key'), BASE . '/api/stream/track');
}
foreach (['360p', '480p', '720p'] as $quality) {
    $response = twoCommandExchange($owners[$quality], '/api/transcode/sessions/', 'POST', ['videoId' => $state['video'], 'qualityTier' => $quality])->complete(20);
    ensure($response->status() === 201, 'Video capacity admission failed.');
    $sessions[] = jsonBody($response);
}
ensure(count(array_unique(array_column($sessions, 'jobId'))) === 3, 'Expected three independent capacity jobs.');
$state['job'] = $sessions[0]['jobId']; $state['session'] = $sessions[0]['publicId']; save($state);
$oldOutput = decodeVideo($auth, $state);
$initialRuntime = row($db, $state);
$initialAttempt = mediaAttempt($state['job']);
$retainedFiles = [];
foreach (glob($initialAttempt['outputDirectory'] . '/*') ?: [] as $path) {
    if (is_file($path) && (str_ends_with($path, '.mp4') || str_ends_with($path, '.m4s'))) { $retainedFiles[$path] = file_get_contents($path); }
}
ensure(count($retainedFiles) >= 2, 'Initial native output omitted retainable init and fragment.');
$unaffected = [];
foreach ($sessions as $index => $session) {
    $attempt = mediaAttempt($session['jobId']);
    if ($index > 0) {
        $runtime = row($db, ['job' => $session['jobId']]);
        $unaffected[$session['jobId']] = ['generation' => $runtime['generation'], 'desiredRevision' => $runtime['desired_revision'], 'desired' => $runtime['desired'], 'identity' => $attempt['identity']];
    }
}
$audio = $auth->open('id=' . $state['track'] . '&format=mp3&bitrate=192000', timeoutSeconds: 120);
ensure($audio->until(static fn (HttpExchange $value): bool => strlen($value->body) >= 8192, 30), 'Native audio did not start.');
file_put_contents(ROOT . '/capacity-ready', 'ready');
await(static fn (): bool => is_file(ROOT . '/capacity-pressure-start'), 'CPU pressure barrier', 60);
$pressureStart = hrtime(true) / 1e9;
$timings = []; $attachments = [];
$control = static function (array $payload, string $status, bool $socket = false) use ($auth, $db, &$state, &$timings): array {
    $start = hrtime(true);
    if ($socket) { mediaSocket($auth, ['sessionId' => $state['session'], ...$payload]); }
    else {
        $response = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'] . '/position', 'POST', $payload)->complete(3);
        $receipt = jsonBody($response)['data'] ?? [];
        ensure($response->status() === 200 && ($receipt['requestId'] ?? null) === $payload['requestId'] && ($receipt['status'] ?? null) === 'ok',
            'Position handling receipt failed: ' . json_encode(['httpStatus' => $response->status(), 'responseKeys' => array_keys(jsonBody($response)), 'handlingStatus' => $receipt['status'] ?? null, 'expectedRequestId' => $payload['requestId'], 'actualRequestId' => $receipt['requestId'] ?? null], JSON_THROW_ON_ERROR));
    }
    $current = acknowledged($db, $state, $status);
    $timings[] = ['action' => $payload['action'], 'transport' => $socket ? 'socket' : 'http', 'seconds' => (hrtime(true) - $start) / 1e9,
        'desiredRevision' => (int) $current['desired_revision'], 'observedRevision' => (int) $current['observed_revision'], 'generation' => (int) $current['generation']];
    return $current;
};
$attach = static function (string $phase) use ($auth, $db, &$state, &$attachments): void {
    $before = row($db, $state);
    $attempts = MediaControlObservation::launchesForJob(mediaJobUuid($state['job']));
    $requests = [];
    foreach (range(1, 4) as $_) { $requests[] = twoCommandExchange($auth, '/api/transcode/sessions/', 'POST', ['videoId' => $state['video'], 'qualityTier' => '360p']); }
    foreach ($requests as $request) {
        $request->complete(20);
        $body = jsonBody($request);
        ensure($request->status() === 201 && $body['publicId'] === $state['session'] && $body['jobId'] === $state['job'], 'Attachment changed owned session or job: ' . json_encode(['status' => $request->status(), 'expectedSession' => $state['session'], 'actualSession' => $body['publicId'] ?? null, 'expectedJob' => $state['job'], 'actualJob' => $body['jobId'] ?? null], JSON_THROW_ON_ERROR));
    }
    $after = row($db, $state);
    ensure($after['generation'] === $before['generation'] && $after['desired_revision'] === $before['desired_revision'], 'Attachment changed playback intent.');
    ensure(MediaControlObservation::launchesForJob(mediaJobUuid($state['job'])) === $attempts, 'Attachment launched another encoder.');
    $references = (int) $db->fetchOne('SELECT reference_count FROM transcode_jobs WHERE public_id=?', [$state['job']]);
    ensure($references === 1, 'Attachment increased owned job references.');
    $attachments[] = ['phase' => $phase, 'requests' => count($requests), 'references' => $references, 'generation' => (int) $after['generation'], 'attempts' => count($attempts)];
};
// Begin admission without waiting for its startup response; the durable pending
// session is cancellable while all three video slots remain occupied.
$pendingRequest = twoCommandExchange($owners['1080p'], '/api/transcode/sessions/', 'POST', ['videoId' => $state['video'], 'qualityTier' => '1080p']);
$pending = await(static function () use ($pendingRequest, $db): array|false {
    $pendingRequest->until(static fn (HttpExchange $value): bool => $value->done, .01);
    $value = $db->fetchAssociative("SELECT s.public_id AS session, j.public_id AS job, j.id AS native_job, r.outcome, r.claim_token FROM transcode_runtime_state r JOIN transcode_jobs j ON j.id=r.job_id JOIN transcode_sessions s ON s.id=r.session_id WHERE j.quality_tier_name='1080p'");
    return $value !== false ? $value : false;
}, 'pending fourth-quality admission', 3);
ensure($pending['outcome'] === 'pending' && $pending['claim_token'] === null, 'Fourth video admission was not pending under full capacity.');
ensure(MediaControlObservation::launchesForJob($pending['native_job']) === [], 'Pending admission launched an encoder.');
$pendingCancel = twoCommandExchange($owners['1080p'], '/api/transcode/sessions/' . $pending['session'], 'DELETE')->complete(3);
ensure($pendingCancel->status() === 200, 'Pending cancellation failed.');
$pendingRequest->complete(10);
ensure($pendingRequest->status() === 503, 'Cancelled pending admission unexpectedly succeeded.');
$pendingRuntime = row($db, ['job' => $pending['job']]);
ensure($pendingRuntime['outcome'] === 'cancelled', 'Pending cancellation lost terminal state.');
ensure((int) $db->fetchOne('SELECT reference_count FROM transcode_jobs WHERE public_id=?', [$pending['job']]) === 0, 'Pending cancellation retained a reference.');
$attach('running');
$pauseId = Uuid::v7()->toString();
$paused = $control(['position' => 0, 'action' => 'pause', 'requestId' => $pauseId], 'paused', true);
$attach('paused');
$resumed = $control(['position' => 0, 'action' => 'resume', 'requestId' => Uuid::v7()->toString()], 'running');
$replayed = $control(['position' => 0, 'action' => 'pause', 'requestId' => $pauseId], 'running', true);
ensure($replayed['desired_revision'] === $resumed['desired_revision'], 'Reconnected socket replay reapplied old pause.');
$control(['position' => 0, 'action' => 'pause', 'requestId' => Uuid::v7()->toString()], 'paused');
$seekStart = hrtime(true);
$seekResponse = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'] . '/position', 'POST', ['position' => 30, 'action' => 'seek', 'generation' => (int) $initialRuntime['generation'], 'requestId' => Uuid::v7()->toString()])->complete(3);
$seekHandlingSeconds = (hrtime(true) - $seekStart) / 1e9;
ensure($seekResponse->status() === 200, 'Paused seek failed.');
$current = acknowledged($db, $state, 'paused', 30);
$seekAcknowledgedAt = hrtime(true) / 1e9;
ensure((int) $current['generation'] > (int) $initialRuntime['generation'], 'Seek did not advance generation.');
await(static fn (): bool => !mediaIdentityAlive($initialAttempt['identity']), 'exact old encoder retirement', 30);
$oldRetiredAt = hrtime(true) / 1e9;
$replacement = mediaAttempt($state['job']);
$replacementObservedAt = hrtime(true) / 1e9;
ensure($replacement['identity'] !== $initialAttempt['identity'], 'Seek reused old native identity.');
$remainingSeekSeconds = 30 - (hrtime(true) - $seekStart) / 1e9;
ensure($remainingSeekSeconds > 0, 'Seek readiness watchdog expired before delivery.');
$decoded = decodeVideo($auth, $state, $remainingSeekSeconds);
$verificationDoneAt = hrtime(true) / 1e9;
$seekSeconds = $decoded['deliveryReadyAt'] - $seekStart / 1e9;
$seekConditions = ['withinWatchdog' => $seekSeconds <= 30, 'paused' => $decoded['status'] === 'paused', 'positionRetained' => (float) $decoded['position'] === 30.0];
ensure(!in_array(false, $seekConditions, true), 'Paused seek exceeded readiness watchdog or lost intent: ' . json_encode([
    'seekStart' => $seekStart / 1e9, 'ackAt' => $seekAcknowledgedAt, 'oldRetiredAt' => $oldRetiredAt,
    'replacementObservedAt' => $replacementObservedAt, 'pairedDeliveryAt' => $decoded['deliveryReadyAt'], 'verificationDoneAt' => $verificationDoneAt,
    'seekSeconds' => $seekSeconds, 'watchdogSeconds' => 30,
    'generation' => $decoded['generation'], 'status' => $decoded['status'], 'position' => $decoded['position'], 'conditions' => $seekConditions,
], JSON_THROW_ON_ERROR));
ensure(is_dir($initialAttempt['outputDirectory']) || mkdir($initialAttempt['outputDirectory'], 0750, true), 'Cannot retain old output directory.');
foreach ($retainedFiles as $path => $bytes) { ensure(file_put_contents($path, $bytes) === strlen($bytes), 'Cannot retain old output bytes.'); }
$retained = [];
foreach ($oldOutput['tracks'] as $kind => $track) {
    foreach (['initUrl', 'segmentUrl'] as $key) {
        $response = (new HttpExchange($track[$key], []))->complete(5);
        ensure(in_array($response->status(), [403, 404, 410], true), 'Retired generation delivered bytes.');
        $retained[$kind . '-' . $key] = $response->status();
    }
}
$stale = $control(['position' => 45, 'action' => 'seek', 'generation' => (int) $initialRuntime['generation'], 'requestId' => Uuid::v7()->toString()], 'paused', true);
ensure($stale['desired_revision'] === $current['desired_revision'] && $stale['generation'] === $current['generation'], 'Stale socket seek changed current generation.');
$control(['position' => 30, 'action' => 'resume', 'requestId' => Uuid::v7()->toString()], 'running');
// Collect a sustained acknowledgement sample while all four native slots are admitted.
for ($index = 0; $index < 40 || hrtime(true) / 1e9 < $pressureStart + 60; ++$index) {
    $control(['position' => 30, 'action' => $index % 2 === 0 ? 'pause' : 'resume', 'requestId' => Uuid::v7()->toString()], $index % 2 === 0 ? 'paused' : 'running');
    usleep(100000);
}
$cancelStart = hrtime(true);
$response = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'], 'DELETE')->complete(3);
$cancelHandlingSeconds = (hrtime(true) - $cancelStart) / 1e9;
ensure($response->status() === 200, 'Active cancellation failed.');
await(static fn (): bool => row($db, $state)['outcome'] === 'cancelled' && !mediaIdentityAlive($replacement['identity']), 'cancelled exact encoder retirement', 30);
$cancelSeconds = (hrtime(true) - $cancelStart) / 1e9;
$cancelled = row($db, $state);
$cancelEnd = hrtime(true) / 1e9;
// Keep a measurable interval with the cancelled video slot deliberately released.
usleep(1_000_000);
foreach (['pause', 'resume', 'seek'] as $action) {
    $response = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'] . '/position', 'POST', ['position' => 60, 'action' => $action, 'requestId' => $action === 'pause' ? $pauseId : Uuid::v7()->toString()])->complete(3);
    ensure(in_array($response->status(), [200, 404, 409, 410], true), 'Unexpected terminal late-control response.');
    $after = row($db, $state);
    ensure($after['outcome'] === 'cancelled' && $after['desired_revision'] === $cancelled['desired_revision'] && $after['generation'] === $cancelled['generation'], 'Late control revived cancelled session.');
}
$admission = twoCommandExchange($auth, '/api/transcode/sessions/', 'POST', ['videoId' => $state['video'], 'qualityTier' => '360p'])->complete(20);
ensure($admission->status() === 201 && jsonBody($admission)['publicId'] !== $state['session'], 'Deliberate new admission did not create a session.');
$newSession = jsonBody($admission);
$newAttempt = mediaAttempt($newSession['jobId']);
foreach ($unaffected as $job => $before) {
    $after = row($db, ['job' => $job]);
    ensure($after['generation'] === $before['generation'] && $after['desired_revision'] === $before['desiredRevision'] && $after['desired'] === $before['desired'], 'Controls changed a different owner playback intent.');
    ensure(mediaIdentityAlive($before['identity']), 'Controls retired a different owner encoder.');
}
MediaControlObservation::assertNoOverlap();
ensure(MediaControlObservation::launchesForJob($pending['native_job']) === [], 'Cancelled pending work launched after capacity became available.');
$result = ['videoJobs' => array_column($sessions, 'jobId'), 'independentOwners' => count($owners), 'unaffectedOwnerJobs' => array_keys($unaffected), 'attachments' => $attachments, 'desiredStateSamples' => $timings,
    'pressureStartedAt' => $pressureStart, 'pressureEndedAt' => hrtime(true) / 1e9,
    'cancelStartedAt' => $cancelStart / 1e9, 'cancelRetiredAt' => $cancelEnd,
    'seekHandlingSeconds' => $seekHandlingSeconds, 'cancelHandlingSeconds' => $cancelHandlingSeconds, 'seekStartedAt' => $seekStart / 1e9, 'seekReadyAt' => $seekStart / 1e9 + $seekSeconds, 'seekReadinessSeconds' => $seekSeconds, 'cancelRetirementSeconds' => $cancelSeconds,
    'generations' => [(int) $initialRuntime['generation'], (int) $current['generation']], 'retiredOutputStatuses' => $retained, 'retainedOutputFiles' => count($retainedFiles),
    'attempts' => ['initial' => $initialAttempt, 'replacement' => $replacement, 'newAdmission' => $newAttempt],
    'audioBytes' => strlen($audio->body), 'socketReconnectReplay' => true,
    'pendingCancellation' => ['job' => $pending['job'], 'startupStatus' => $pendingRequest->status(), 'outcome' => $pendingRuntime['outcome'], 'nativeLaunches' => 0, 'references' => 0]];
