<?php

declare(strict_types=1);

use App\QoL\Domain\Service\LearningModel;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\WebRuntime\HttpExchange;
use App\Tests\Fixtures\WebRuntime\StreamClient;

/**
 * Drives the load proof. It first warms the playback governor with 1080p encodes until the
 * governor has learned at least its learning sample count and turned active, then waits at a
 * barrier while the drill starts its web load, and then runs every configured video slot at
 * 1080p and every audio slot together. Each video session reports playback progress in real
 * time, capped at the encoded output, as a player would, so an encode that outpaces playback
 * stays inside the buffer-ahead window and runs to completion. Each audio lane requests one
 * rendition after another of its own track while any video still encodes.
 *
 * @var string $project
 * @var Doctrine\ORM\EntityManagerInterface $manager
 */
require_once $project . '/tests/Fixtures/Worker/MediaControlObservation.php';

const LOAD_WARM_UP_OUTPUT_SECONDS = 10.0;
const LOAD_WARM_UP_ROUNDS = 20;
const LOAD_RUN_SECONDS = 1200;
const LOAD_SAMPLE_SECONDS = .5;
const LOAD_PROGRESS_SECONDS = 2.0;
/** Distinct renditions per lane: each format at each bitrate the track endpoint snaps to. */
const LOAD_RENDITIONS = [['mp3', 192_000], ['aac', 192_000], ['opus', 128_000], ['mp3', 128_000], ['aac', 128_000], ['opus', 96_000],
    ['mp3', 160_000], ['aac', 160_000], ['opus', 160_000], ['mp3', 96_000], ['aac', 96_000], ['opus', 64_000], ['mp3', 64_000], ['aac', 64_000], ['opus', 192_000]];

$state = state();
$load = $state['load'] ?? throw new RuntimeException('Load sources were not prepared.');
$profile = DeploymentProfile::fromEnvironment();
[$videoSlots, $audioSlots] = [$profile->videoEncoderSlots, $profile->audioEncoderSlots];
ensure($videoSlots > 0 && $audioSlots > 0 && count($load['tracks']) >= $audioSlots, 'The load proof needs video and audio slots and a track per audio slot.');
// Observation reads PostgreSQL directly, so the drill's own queries never wait in the pooled application pool.
$direct = Doctrine\DBAL\DriverManager::getConnection((new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))
    ->parse((string) getenv('BAANDER_TEST_DIRECT_DATABASE_URL')));
ensure(mkdir(ROOT . '/cost-progress', 0700) || is_dir(ROOT . '/cost-progress'), 'Cannot enable native progress evidence.');
/** @var list<StreamClient> $owners */
$owners = [];
foreach (range(0, max($videoSlots, $audioSlots) - 1) as $index) {
    $owners[] = drillOwner($manager, $project, 'media-load-' . $index);
}
$now = static fn (): float => hrtime(true) / 1e9;
$clock = ['monotonic' => $now(), 'wall' => microtime(true)];
$governorPath = rtrim((string) getenv('CONVERT_STORAGE_PATH'), '/') . '/qol-state/governor_state.json';
/** @return array{state:string,samples:int,hardwareSamples:int}|null */
$governor = static function () use ($governorPath): ?array {
    clearstatcache(true, $governorPath);
    $saved = is_file($governorPath) ? json_decode((string) file_get_contents($governorPath), true) : null;
    if (!is_array($saved) || !is_array($saved['governor']['model']['samples'] ?? null)) {
        return null;
    }
    $samples = $saved['governor']['model']['samples'];
    return ['state' => (string) ($saved['governor']['state'] ?? ''), 'samples' => count($samples),
        'hardwareSamples' => count(array_filter($samples, static fn (array $sample): bool => ($sample['hardwareAccelerated'] ?? false) === true))];
};
/** @return array<string,mixed> */
$job = static fn (string $publicId): array => $direct->fetchAssociative(
    'SELECT j.id, j.public_id, j.status, r.outcome, r.generation, r.desired_revision, r.claim_token IS NOT NULL AS claimed, r.error
     FROM transcode_jobs j LEFT JOIN transcode_runtime_state r ON r.job_id=j.id WHERE j.public_id=?',
    [$publicId],
) ?: throw new RuntimeException('Load job disappeared.');
$launch = static function (string $jobUuid): ?array {
    $launches = is_file(ROOT . '/native-launches.jsonl') ? MediaControlObservation::launchesForJob($jobUuid) : [];
    return $launches === [] ? null : $launches[array_key_last($launches)];
};
$outputSeconds = static function (array $launch) use ($load): ?float {
    $progress = @file_get_contents(ROOT . '/cost-progress/' . $launch['identity']['pid'] . '.txt');
    if (!is_string($progress) || preg_match_all('/^frame=(\d+)$/m', $progress, $frames) < 1) {
        return null;
    }
    return (int) end($frames[1]) / $load['frameRate'];
};
$admit = static function (array $owners) use ($load): array {
    $requests = array_map(static fn (StreamClient $owner): HttpExchange => twoCommandExchange($owner, '/api/transcode/sessions/', 'POST',
        ['requestId' => Uuid::v7()->toString(), 'videoId' => $load['video'], 'qualityTier' => '1080p']), $owners);
    $sessions = [];
    foreach (HttpExchange::completeAll($requests, 120) as $index => $response) {
        ensure($response->status() === 201, 'Load video admission failed with status ' . $response->status() . ': ' . substr($response->body, 0, 300));
        $body = jsonBody($response);
        $sessions[] = ['owner' => $index, 'session' => $body['publicId'], 'job' => $body['jobId'], 'admittedAt' => hrtime(true) / 1e9];
    }
    return $sessions;
};
$terminal = static fn (array $row): bool => in_array($row['outcome'], ['cancelled', 'complete', 'failed', 'refused'], true) && !$row['claimed'];

// Warm-up: rounds of every video slot, each encode cancelled once it has produced enough
// output to become one learning sample, until the persisted learning state is active.
$warmUp = [];
$warmJobs = [];
for ($round = 1; ; ++$round) {
    $learned = $governor();
    if ($learned !== null && $learned['samples'] >= LearningModel::MIN_SAMPLES && $learned['state'] === 'active') {
        break;
    }
    ensure($round <= LOAD_WARM_UP_ROUNDS, 'The governor did not learn within ' . LOAD_WARM_UP_ROUNDS . ' warm-up rounds: ' . json_encode($learned));
    $startedAt = $now();
    $sessions = $admit(array_slice($owners, 0, $videoSlots));
    $cancelled = [];
    await(static function () use (&$sessions, &$cancelled, $owners, $job, $launch, $outputSeconds, $direct): bool {
        foreach ($sessions as $index => &$session) {
            if (isset($cancelled[$index])) {
                continue;
            }
            $session['uuid'] ??= (string) $direct->fetchOne('SELECT id FROM transcode_jobs WHERE public_id=?', [$session['job']]);
            $session['launch'] ??= $launch($session['uuid']);
            $produced = $session['launch'] === null ? null : $outputSeconds($session['launch']);
            if ($produced !== null && $produced >= LOAD_WARM_UP_OUTPUT_SECONDS) {
                $status = twoCommandExchange($owners[$session['owner']], '/api/transcode/sessions/' . $session['session'], 'DELETE')->complete(30)->status();
                ensure($status === 200, 'Warm-up cancellation failed with status ' . $status);
                $session['outputSeconds'] = $produced;
                $cancelled[$index] = true;
            } else {
                ensure(!in_array($job($session['job'])['outcome'], ['failed', 'refused'], true), 'Warm-up encode failed: ' . json_encode($job($session['job'])));
            }
        }
        unset($session);
        return count($cancelled) === count($sessions);
    }, 'warm-up output', 300);
    foreach ($sessions as $session) {
        await(static fn (): bool => $terminal($job($session['job'])), 'warm-up release', 60);
        $warmJobs[] = $session['uuid'];
    }
    // Each child merges its sample as it stops, just after its release. A child whose encoder
    // reported too little output learns nothing, so the next round makes up for it.
    $expected = ($learned['samples'] ?? 0) + count($sessions);
    for ($wait = microtime(true) + 30; ($governor()['samples'] ?? 0) < $expected && microtime(true) < $wait;) {
        usleep(200_000);
    }
    $warmUp[] = ['round' => $round, 'startedAt' => $startedAt, 'endedAt' => $now(), 'jobs' => array_column($sessions, 'uuid'),
        'outputSeconds' => array_column($sessions, 'outputSeconds'), 'governorAfter' => $governor()];
}
$learned = $governor();
file_put_contents(ROOT . '/load-warm', json_encode($learned, JSON_THROW_ON_ERROR));
await(static fn (): bool => is_file(ROOT . '/load-go'), 'load barrier', 300);

// The run: every video slot at 1080p, every audio slot, until each video has completed.
$runStartedAt = $now();
$sessions = $admit(array_slice($owners, 0, $videoSlots));
foreach ($sessions as &$session) {
    $session['uuid'] = (string) $direct->fetchOne('SELECT id FROM transcode_jobs WHERE public_id=?', [$session['job']]);
    $session += ['launch' => null, 'firstOutputAt' => null, 'completedAt' => null, 'timeline' => [], 'progressSent' => 0, 'progressRejected' => [], 'nextProgress' => 0.0];
}
unset($session);
$audioStart = static function (int $lane, int $index) use ($owners, $load): array {
    [$format, $bitrate] = LOAD_RENDITIONS[$index % count(LOAD_RENDITIONS)];
    return ['lane' => $lane, 'index' => $index, 'format' => $format, 'bitrate' => $bitrate, 'startedAt' => hrtime(true) / 1e9,
        'exchange' => $owners[$lane]->open('id=' . $load['tracks'][$lane] . '&format=' . $format . '&bitrate=' . $bitrate, timeoutSeconds: 120)];
};
$lanes = [];
foreach (range(0, $audioSlots - 1) as $lane) {
    $lanes[$lane] = ['current' => $audioStart($lane, 0), 'next' => 1];
}
$renditions = [];
$occupancy = [];
$pending = [];
$nextSample = 0.0;
$deadline = $runStartedAt + LOAD_RUN_SECONDS;
while (true) {
    $current = $now();
    ensure($current < $deadline, 'The load run exceeded its ' . LOAD_RUN_SECONDS . '-second watchdog.');
    $videoDone = array_all($sessions, static fn (array $session): bool => $session['completedAt'] !== null);
    foreach ($lanes as $lane => &$laneState) {
        if ($laneState['current'] === null) {
            continue;
        }
        if ($laneState['current']['exchange']->poll()) {
            $exchange = $laneState['current']['exchange'];
            $record = array_diff_key($laneState['current'], ['exchange' => true]) + ['endedAt' => $current, 'status' => $exchange->status(),
                'bytes' => strlen($exchange->body), 'contentType' => $exchange->header('Content-Type')];
            ensure($record['status'] === 200 && $record['bytes'] > 0, 'Load audio rendition failed: ' . json_encode($record));
            // A finished exchange stays referenced by its curl callbacks until a cycle collection;
            // its body is dropped now so hundreds of renditions do not accumulate.
            $exchange->body = '';
            $renditions[] = $record;
            $laneState['current'] = $videoDone ? null : $audioStart($lane, $laneState['next']++);
        }
    }
    unset($laneState);
    foreach ($pending as $index => $request) {
        // Progress is advisory: a report the web answered late or refused is recorded, not fatal.
        try {
            $finished = $request->poll();
            $status = $finished ? $request->status() : null;
        } catch (RuntimeException $error) {
            [$finished, $status] = [true, $error->getMessage()];
        }
        if ($finished) {
            if ($status !== 200) {
                $sessions[$index]['progressRejected'][] = $status;
            }
            unset($pending[$index]);
        }
    }
    if ($current >= $nextSample) {
        $nextSample = $current + LOAD_SAMPLE_SECONDS;
        gc_collect_cycles();
        foreach ($sessions as $index => &$session) {
            if ($session['completedAt'] !== null) {
                continue;
            }
            $row = $job($session['job']);
            ensure(!in_array($row['outcome'], ['failed', 'refused', 'cancelled'], true), 'Load video ' . $session['job'] . ' ended as ' . $row['outcome'] . ': ' . ($row['error'] ?? ''));
            $session['launch'] ??= $launch($session['uuid']);
            $produced = $session['launch'] === null ? null : $outputSeconds($session['launch']);
            if ($produced !== null) {
                $session['firstOutputAt'] ??= $current;
                $session['timeline'][] = [round($current - $runStartedAt, 3), round($produced, 3)];
            }
            if ($row['outcome'] === 'complete' && $row['status'] === 'completed') {
                $session['completedAt'] = $current;
                continue;
            }
            // A player plays from its first frame in real time and never beyond the encoded output,
            // and reports again only once its previous report was answered.
            if ($produced !== null && $current >= $session['nextProgress'] && $row['generation'] !== null && !isset($pending[$index])) {
                $session['nextProgress'] = $current + LOAD_PROGRESS_SECONDS;
                $position = min($current - $session['firstOutputAt'], $produced);
                $pending[$index] = twoCommandExchange($owners[$session['owner']], '/api/transcode/sessions/' . $session['session'] . '/position', 'POST',
                    ['position' => round($position, 3), 'action' => 'progress', 'generation' => (int) $row['generation'], 'revision' => (int) $row['desired_revision'], 'requestId' => Uuid::v7()->toString()]);
                ++$session['progressSent'];
            }
        }
        unset($session);
        $occupancy[] = ['t' => round($current - $runStartedAt, 3),
            'claimedVideo' => (int) $direct->fetchOne('SELECT count(*) FROM transcode_runtime_state WHERE claim_token IS NOT NULL'),
            'runningAudio' => (int) $direct->fetchOne("SELECT count(*) FROM audio_rendition_runtime WHERE state='running'")];
    }
    if ($videoDone && array_all($lanes, static fn (array $laneState): bool => $laneState['current'] === null) && $pending === []) {
        break;
    }
    usleep(5_000);
}
$runEndedAt = $now();
file_put_contents(ROOT . '/load-done', 'done');
$streams = array_map(static fn (array $session): array => ['job' => $session['uuid'], 'session' => $session['session'],
    'admittedAt' => round($session['admittedAt'] - $runStartedAt, 3), 'firstOutputAt' => $session['firstOutputAt'] === null ? null : round($session['firstOutputAt'] - $runStartedAt, 3),
    'completedAt' => round($session['completedAt'] - $runStartedAt, 3), 'launch' => $session['launch'] === null ? null : array_diff_key($session['launch'], ['predecessors' => true]),
    'timeline' => $session['timeline'], 'progressSent' => $session['progressSent'], 'progressRejected' => $session['progressRejected']], $sessions);
$result = ['profile' => json_decode($profile->toJson(), true), 'clock' => $clock, 'learningSamples' => LearningModel::MIN_SAMPLES,
    'warmUp' => $warmUp, 'warmUpJobs' => $warmJobs, 'governorBeforeRun' => $learned, 'governorAfterRun' => $governor(),
    'runStartedAt' => $runStartedAt, 'runEndedAt' => $runEndedAt, 'source' => $load,
    'streams' => $streams, 'renditions' => $renditions, 'occupancy' => $occupancy];
