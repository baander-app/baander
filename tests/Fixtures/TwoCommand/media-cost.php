<?php

declare(strict_types=1);

use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\WebRuntime\HttpExchange;
use App\Tests\Fixtures\WebRuntime\StreamClient;

/**
 * Drives one job per media kind in isolation, then the configured slots together,
 * and records phase boundaries for the samplers in the worker units. The client
 * never sends playback progress, so a video encode is measured until the worker's
 * buffer-ahead throttle stops it.
 *
 * @var string $project
 * @var Doctrine\ORM\EntityManagerInterface $manager
 * @var Doctrine\DBAL\Connection $db
 */
require_once $project . '/tests/Fixtures/Worker/MediaControlObservation.php';

const COST_VIDEO_WINDOW_SECONDS = 45;
const COST_CONCURRENT_WINDOW_SECONDS = 50;
const COST_COPY_HOLD_SECONDS = 5;
const COST_IDLE_SECONDS = 20;

$state = state();
$cost = $state['cost'] ?? throw new RuntimeException('Cost sources were not prepared.');
ensure(mkdir(ROOT . '/cost-progress', 0700) || is_dir(ROOT . '/cost-progress'), 'Cannot enable native progress evidence.');
/** @var list<StreamClient> $owners */
$owners = [];
foreach (range(0, 7) as $index) {
    $email = 'media-cost-' . $index . '@baander.app';
    $owner = new App\Auth\Infrastructure\Doctrine\Entity\UserEntity(
        new App\Shared\Domain\Model\PublicId(), 'Media cost ' . $index, $email, 'unused-password', '', roles: ['ROLE_ADMIN'],
    );
    $owner->markEmailAsVerified();
    $manager->persist($owner);
    $manager->flush();
    $owners[] = new StreamClient($manager, $email,
        (string) file_get_contents($project . '/config/secrets/oauth/private.key'), BASE . '/api/stream/track', audience: BASE);
}
$phases = [];
$now = static fn (): float => hrtime(true) / 1e9;
/** @return array<string,mixed> */
$governor = static function () use ($owners): array {
    $snapshot = [];
    foreach (['status', 'streams'] as $resource) {
        $response = twoCommandExchange($owners[0], '/api/admin/qol/' . $resource)->complete(5);
        $snapshot[$resource] = ['status' => $response->status(), 'body' => json_decode($response->body, true)];
    }
    return $snapshot;
};
/** @param callable(): array<string,mixed> $body */
$phase = static function (string $name, callable $body) use (&$phases, $now, $governor): void {
    $before = $governor();
    $startedAt = $now();
    $evidence = $body();
    $phases[] = ['name' => $name, 'startedAt' => $startedAt, 'endedAt' => $now(), 'governorBefore' => $before, 'governorAfter' => $governor()] + $evidence;
    file_put_contents(ROOT . '/cost-phases.json', json_encode($phases, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
};
$jobUuid = static fn (string $publicId): string => (string) $db->fetchOne('SELECT id FROM transcode_jobs WHERE public_id=? OR id::text=?', [$publicId, $publicId]);
$job = static fn (string $publicId): array => $db->fetchAssociative(
    'SELECT j.id, j.public_id, j.quality_tier_name, j.status, r.outcome, r.claim_token IS NOT NULL AS claimed FROM transcode_jobs j LEFT JOIN transcode_runtime_state r ON r.job_id=j.id WHERE j.public_id=? OR j.id::text=?',
    [$publicId, $publicId],
) ?: throw new RuntimeException('Cost job disappeared.');
$launched = static fn (string $publicId): array => await(static function () use ($publicId, $jobUuid): array|false {
    // The observation ledger appears with the first native launch.
    $launches = is_file(ROOT . '/native-launches.jsonl') ? MediaControlObservation::launchesForJob($jobUuid($publicId)) : [];
    return $launches === [] ? false : $launches[0];
}, 'native launch', 60);
$released = static fn (string $publicId, float $seconds = 30) => await(static function () use ($publicId, $job): bool {
    $current = $job($publicId);
    return !$current['claimed'] && in_array($current['outcome'], ['cancelled', 'complete', 'failed'], true);
}, 'worker release', $seconds);
$encode = static function (StreamClient $owner) use ($cost): array {
    $response = twoCommandExchange($owner, '/api/transcode/sessions/', 'POST', ['requestId' => Uuid::v7()->toString(), 'videoId' => $cost['video'], 'qualityTier' => '1080p'])->complete(30);
    ensure($response->status() === 201, 'Cost video admission failed with status ' . $response->status());
    return jsonBody($response);
};
$cancel = static function (StreamClient $owner, string $session): int {
    return twoCommandExchange($owner, '/api/transcode/sessions/' . $session, 'DELETE')->complete(10)->status();
};
$copy = static function (StreamClient $owner) use ($cost): HttpExchange {
    return twoCommandExchange($owner, '/api/stream/delivery', 'POST', ['kind' => 'video', 'id' => $cost['video'], 'containers' => ['mp4'],
        'videoCodecs' => ['h264'], 'audioCodecs' => ['aac'], 'transports' => ['hls'], 'requestId' => Uuid::v7()->toString()]);
};
$copied = static function (HttpExchange $response): string {
    $decision = jsonBody($response);
    ensure($response->status() === 200 && ($decision['kind'] ?? null) === 'copy' && is_string($decision['jobId'] ?? null),
        'Cost source did not negotiate stream copy: ' . json_encode(['status' => $response->status(), 'kind' => $decision['kind'] ?? null, 'reason' => $decision['reason'] ?? null], JSON_THROW_ON_ERROR));
    return $decision['jobId'];
};
$cancelCopy = static function (StreamClient $owner, string $jobId) use ($db, $cancel): int {
    $session = $db->fetchOne('SELECT s.public_id FROM transcode_runtime_state r JOIN transcode_sessions s ON s.id=r.session_id JOIN transcode_jobs j ON j.id=r.job_id WHERE j.public_id=? OR j.id::text=?', [$jobId, $jobId]);
    ensure(is_string($session), 'Copy session is missing.');
    return $cancel($owner, $session);
};
$audio = static fn (StreamClient $owner, string $track, string $format, int $bitrate): HttpExchange
    => $owner->open('id=' . $track . '&format=' . $format . '&bitrate=' . $bitrate, timeoutSeconds: 120);
$drain = static function (array $exchanges, float $seconds): array {
    $deadline = microtime(true) + $seconds;
    while (!array_all($exchanges, static fn (HttpExchange $exchange): bool => $exchange->done) && microtime(true) < $deadline) {
        foreach ($exchanges as $exchange) {
            $exchange->until(static fn (HttpExchange $value): bool => $value->done, .05);
        }
    }
    return array_map(static fn (HttpExchange $exchange): array => ['status' => $exchange->status(), 'bytes' => strlen($exchange->body), 'done' => $exchange->done], $exchanges);
};
$audioFormats = ['opus' => 128_000, 'aac' => 192_000, 'mp3' => 192_000];

// Service baselines with no media work admitted.
$phase('idle', static function (): array {
    usleep(COST_IDLE_SECONDS * 1_000_000);
    return [];
});
$phase('isolated-video-encode', static function () use ($owners, $encode, $cancel, $launched, $released, $job): array {
    $session = $encode($owners[0]);
    $launch = $launched($session['jobId']);
    usleep(COST_VIDEO_WINDOW_SECONDS * 1_000_000);
    $cancelStatus = $cancel($owners[0], $session['publicId']);
    $released($session['jobId']);
    return ['jobs' => [['kind' => 'video-encode', 'job' => $job($session['jobId']), 'launch' => $launch]], 'cancelStatus' => $cancelStatus];
});
// A copy reaches the buffer-ahead limit within a second, so it is held briefly
// and then cancelled like an encode rather than awaited to completion.
$phase('isolated-video-copy', static function () use ($owners, $copy, $copied, $launched, $released, $job, $cancelCopy): array {
    $jobId = $copied($copy($owners[1])->complete(30));
    $launch = $launched($jobId);
    usleep(COST_COPY_HOLD_SECONDS * 1_000_000);
    $cancelStatus = $cancelCopy($owners[1], $jobId);
    $released($jobId);
    return ['jobs' => [['kind' => 'video-copy', 'job' => $job($jobId), 'launch' => $launch]], 'cancelStatus' => $cancelStatus];
});
foreach ($audioFormats as $format => $bitrate) {
    $phase('isolated-audio-' . $format, static function () use ($owners, $audio, $drain, $cost, $format, $bitrate): array {
        $responses = $drain([$audio($owners[0], $cost['tracks']['isolated'], $format, $bitrate)], 115);
        ensure($responses[0]['status'] === 200 && $responses[0]['done'] && $responses[0]['bytes'] > 0, 'Isolated ' . $format . ' rendition did not complete.');
        return ['audio' => [['format' => $format, 'bitrate' => $bitrate] + $responses[0]]];
    });
}
$phase('concurrent-slots', static function () use ($owners, $encode, $cancel, $launched, $released, $job, $audio, $drain, $cost, $audioFormats, $jobUuid, $now, $db): array {
    $sessions = array_map($encode, array_slice($owners, 0, 3));
    $launches = array_map(static fn (array $session): array => $launched($session['jobId']), $sessions);
    $renditions = [];
    foreach ($audioFormats as $format => $bitrate) {
        $renditions[$format] = $audio($owners[0], $cost['tracks']['concurrent'], $format, $bitrate);
    }
    // A fourth video and a second track wait while every configured slot is occupied.
    $extraRequest = twoCommandExchange($owners[3], '/api/transcode/sessions/', 'POST', ['requestId' => Uuid::v7()->toString(), 'videoId' => $cost['video'], 'qualityTier' => '1080p']);
    $admitted = array_column($sessions, 'jobId');
    $extra = await(static function () use ($extraRequest, $db, $admitted): array|false {
        $extraRequest->until(static fn (HttpExchange $value): bool => $value->done, .01);
        $value = $db->fetchAssociative("SELECT s.public_id AS session, j.public_id AS job, r.outcome, r.claim_token IS NOT NULL AS claimed FROM transcode_runtime_state r JOIN transcode_jobs j ON j.id=r.job_id JOIN transcode_sessions s ON s.id=r.session_id WHERE j.quality_tier_name='1080p' AND r.outcome NOT IN ('cancelled', 'complete', 'failed') AND j.public_id NOT IN (?, ?, ?)", $admitted);
        return $value !== false ? $value : false;
    }, 'fourth video admission', 10);
    $queuedAudio = $audio($owners[1], $cost['tracks']['queued'], 'mp3', 192_000);
    $occupancy = [];
    $deadline = $now() + COST_CONCURRENT_WINDOW_SECONDS;
    while ($now() < $deadline) {
        foreach ([...$renditions, $queuedAudio, $extraRequest] as $exchange) {
            $exchange->until(static fn (HttpExchange $value): bool => $value->done, .05);
        }
        $occupancy[] = ['t' => $now(), 'claimedVideo' => (int) $db->fetchOne('SELECT count(*) FROM transcode_runtime_state WHERE claim_token IS NOT NULL'),
            'runningAudio' => (int) $db->fetchOne("SELECT count(*) FROM audio_rendition_runtime WHERE state='running'"),
            'fourthLaunches' => count(MediaControlObservation::launchesForJob($jobUuid($extra['job']))),
            'fourth' => $job($extra['job'])];
        usleep(500_000);
    }
    $fourth = ['request' => $extra, 'statusAtWindowEnd' => $job($extra['job']), 'launchesAtWindowEnd' => MediaControlObservation::launchesForJob($jobUuid($extra['job']))];
    $cancelled = [];
    foreach ($sessions as $index => $session) {
        $cancelled[] = $cancel($owners[$index], $session['publicId']);
    }
    $cancelled[] = $cancel($owners[3], $extra['session']);
    foreach ($sessions as $session) {
        $released($session['jobId']);
    }
    $extraRequest->until(static fn (HttpExchange $value): bool => $value->done, 30);
    $fourth['startupStatus'] = $extraRequest->status();
    $responses = $drain([...$renditions, 'queued-mp3' => $queuedAudio], 115);
    $audioEvidence = [];
    foreach ($responses as $key => $response) {
        $audioEvidence[] = ['format' => $key === 'queued-mp3' ? 'mp3' : $key, 'bitrate' => $audioFormats[$key] ?? 192_000, 'queued' => $key === 'queued-mp3'] + $response;
    }
    return ['jobs' => array_map(static fn (array $session, array $launch): array => ['kind' => 'video-encode', 'job' => $job($session['jobId']), 'launch' => $launch], $sessions, $launches),
        'audio' => $audioEvidence, 'fourthVideo' => $fourth, 'occupancy' => $occupancy, 'cancelStatuses' => $cancelled];
});
$phase('concurrent-copies', static function () use ($owners, $copy, $copied, $released, $job, $cancelCopy, $jobUuid): array {
    // Owners without a completed copy, so negotiation cannot return a cached artifact.
    // A copy request that finds every video slot occupied is refused rather than queued.
    $copyOwners = [];
    $jobs = [];
    $refused = [];
    foreach (HttpExchange::completeAll(array_map($copy, array_slice($owners, 4, 4)), 60) as $index => $response) {
        if ($response->status() === 200) {
            $copyOwners[] = $owners[4 + $index];
            $jobs[] = $copied($response);
        } else {
            $refused[] = ['status' => $response->status(), 'retryAfter' => $response->header('Retry-After')];
        }
    }
    $launches = static fn (): array => array_map(static fn (string $jobId): array => is_file(ROOT . '/native-launches.jsonl') ? MediaControlObservation::launchesForJob($jobUuid($jobId)) : [], $jobs);
    $occupied = await(static function () use ($launches): array|false {
        $current = $launches();
        return count(array_filter($current)) >= 3 ? $current : false;
    }, 'three concurrent copies', 60);
    usleep(COST_COPY_HOLD_SECONDS * 1_000_000);
    $held = $launches();
    $cancelled = array_map($cancelCopy, $copyOwners, $jobs);
    foreach ($jobs as $jobId) {
        $released($jobId);
    }
    return ['jobs' => array_map(static fn (string $jobId, array $launch): array => ['kind' => 'video-copy', 'job' => $job($jobId), 'launch' => $launch[0] ?? null], $jobs, $held),
        'launchedWhenOccupied' => count(array_filter($occupied)), 'launchedAfterHold' => count(array_filter($held)), 'refused' => $refused, 'cancelStatuses' => $cancelled];
});
$result = ['phases' => $phases, 'sources' => $cost];
