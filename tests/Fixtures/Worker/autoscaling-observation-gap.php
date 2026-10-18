<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Worker\Autoscaling\AutoscalingStatus;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Tests\Fixtures\Worker\Autoscaling\Workload;
use App\Tests\Fixtures\Worker\Autoscaling\WorkloadCodec;

$started = hrtime(true) / 1e9;
$deadline = $started + 24;
$namespace = (string) getenv('BAANDER_WORKER_NAMESPACE');
$boot = (string) getenv('BAANDER_WORKER_BOOT_ID');
DeploymentLease::validateIdentity($namespace, $boot);
$directory = (getenv('BAANDER_WORKER_IPC_DIRECTORY') ?: '/run/baander-worker') . '/' . $boot;
$read = static function (string $name) use ($directory): array {
    $raw = file_get_contents($directory . '/' . $name . '.json', false, null, 0, 32769);
    if ($raw === false || strlen($raw) > 32768) { throw new RuntimeException('Invalid bounded qualification observation.'); }
    return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
};
$ledger = $read('admission');
$lease = new DeploymentLease($namespace, $boot, $ledger['epoch']);
$profileFields = json_decode((string) file_get_contents(__DIR__ . '/autoscaling-profile.json'), true, flags: JSON_THROW_ON_ERROR);
$profileFields['maximumConcurrency'] = count($ledger['slots']);
$profile = WorkerAutoscalingProfile::fromJson(json_encode($profileFields, JSON_THROW_ON_ERROR));
if ($profile->maxSampleAgeMs > 6000) { throw new RuntimeException('Observation gap exceeds qualification time budget.'); }
$status = new AutoscalingStatus(dirname($directory), $lease, $profile);
$db = Doctrine\DBAL\DriverManager::getConnection((new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse((string) getenv('DATABASE_URL')));
$db->executeStatement("SET statement_timeout = '1000ms'");
$startTicks = static function (int $pid): ?string {
    $raw = @file_get_contents('/proc/' . $pid . '/stat');
    if (!is_string($raw) || ($end = strrpos($raw, ') ')) === false) { return null; }
    return explode(' ', substr($raw, $end + 2))[19] ?? null;
};
$matches = [];
foreach (glob('/proc/[0-9]*/environ') ?: [] as $path) {
    $raw = @file_get_contents($path, false, null, 0, 131073);
    if (!is_string($raw) || strlen($raw) > 131072) { continue; }
    $identity = [];
    foreach (explode("\0", $raw) as $entry) {
        [$key, $value] = array_pad(explode('=', $entry, 2), 2, '');
        if (in_array($key, ['BAANDER_WORKER_NAMESPACE', 'BAANDER_WORKER_BOOT_ID', 'BAANDER_WORKER_ID', 'BAANDER_WORKER_GENERATION', 'BAANDER_WORKER_UNIT'], true)) {
            $identity[$key] = $value;
        }
    }
    if (($identity['BAANDER_WORKER_NAMESPACE'] ?? null) === $namespace && ($identity['BAANDER_WORKER_BOOT_ID'] ?? null) === $boot
        && ($identity['BAANDER_WORKER_ID'] ?? null) === 'autoscaler' && ($identity['BAANDER_WORKER_GENERATION'] ?? null) === '1'
        && ($identity['BAANDER_WORKER_UNIT'] ?? null) === 'control') {
        $pid = (int) basename(dirname($path));
        $ticks = $startTicks($pid);
        if ($ticks !== null) { $matches[] = ['pid' => $pid, 'startTicks' => $ticks, 'role' => 'autoscaler', 'generation' => 1]; }
    }
}
if (count($matches) !== 1) { throw new RuntimeException('Expected one exact private autoscaler identity.'); }
$controller = $matches[0];
$stopped = false;
$resume = static function () use (&$stopped, $controller, $startTicks): void {
    /** @var bool $stopped Mutable pause state read when this deferred callback runs. */
    if ($stopped && $startTicks($controller['pid']) === $controller['startTicks']) {
        if (!posix_kill($controller['pid'], SIGCONT)) { throw new RuntimeException('Cannot resume exact autoscaler.'); }
        $stopped = false;
    }
};
register_shutdown_function($resume);
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function (): never { throw new RuntimeException('Observation gap interrupted.'); });
pcntl_signal(SIGINT, static function (): never { throw new RuntimeException('Observation gap interrupted.'); });
$completions = static function () use ($directory): array {
    $result = [];
    $raw = @file_get_contents($directory . '/autoscaling-completions.jsonl', false, null, 0, 65537) ?: '';
    if (strlen($raw) > 65536) { throw new RuntimeException('Completion observations exceeded bound.'); }
    foreach (explode("\n", trim($raw)) as $line) {
        if ($line === '') { continue; }
        $receipt = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if ($receipt['batch'] === 'observation-gap') { $result[] = $receipt; }
    }
    return $result;
};
$healthy = static function (float $now) use ($read, $namespace, $boot, $lease, $db): array {
    $peers = [];
    foreach (['control', 'execution'] as $unit) {
        $peer = $read($unit);
        if ($peer['namespace'] !== $namespace || $peer['bootId'] !== $boot || $peer['epoch'] !== $lease->epoch
            || $peer['unit'] !== $unit || !$peer['ready'] || $peer['draining'] || $now - $peer['time'] < -.25 || $now - $peer['time'] >= 2) {
            throw new RuntimeException('Observation gap disturbed peer authority or freshness.');
        }
        $peers[$unit] = $peer;
    }
    $authority = $db->fetchAssociative('SELECT owner_boot_id, epoch, state, expires_at > clock_timestamp() AS unexpired FROM worker_deployment_leases WHERE namespace = ?', [$namespace]);
    if (!$authority || $authority['owner_boot_id'] !== $boot || (int) $authority['epoch'] !== $lease->epoch
        || $authority['state'] !== 'active' || !in_array($authority['unexpired'], [true, 1, 't', '1'], true)) {
        throw new RuntimeException('Observation gap disturbed deployment lease.');
    }
    return ['sampledAt' => $now, 'peers' => $peers, 'lease' => $authority];
};
$initial = $status->snapshot(hrtime(true) / 1e9);
if ($initial['state'] !== 'known' || !$initial['authorityValid'] || !$initial['controllerFresh'] || $completions() !== []) {
    throw new RuntimeException('Observation gap requires a fresh controller and unused batch.');
}
try {
    // Pause outside a ledger critical section so the outage affects observation,
    // rather than leaving the controller's flock held while it is stopped.
    $lock = fopen($directory . '/admission.lock', 'r+');
    if ($lock === false) { throw new RuntimeException('Cannot open existing admission lock.'); }
    try {
        $lockDeadline = hrtime(true) / 1e9 + .5;
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (hrtime(true) / 1e9 >= $lockDeadline) { throw new RuntimeException('Cannot locate a bounded controller pause boundary.'); }
            usleep(1000);
        }
        $stopped = true;
        if ($startTicks($controller['pid']) !== $controller['startTicks'] || !posix_kill($controller['pid'], SIGSTOP)) {
            throw new RuntimeException('Cannot stop exact autoscaler.');
        }
        $stopDeadline = hrtime(true) / 1e9 + .02;
        do {
            $raw = @file_get_contents('/proc/' . $controller['pid'] . '/stat');
            $end = is_string($raw) ? strrpos($raw, ') ') : false;
            $isStopped = $end !== false && substr($raw, $end + 2, 1) === 'T';
            if ($isStopped) { break; }
            usleep(100);
        } while (hrtime(true) / 1e9 < $stopDeadline);
        if (!$isStopped) { throw new RuntimeException('Autoscaler did not reach its bounded stopped state.'); }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $stoppedAt = hrtime(true) / 1e9;
    $waitUntil = $stoppedAt + $profile->maxSampleAgeMs / 1000 + .2;
    while (hrtime(true) / 1e9 < $waitUntil) { usleep(20000); }
    $transport = new Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport(
        Symfony\Component\Messenger\Bridge\Redis\Transport\Connection::fromDsn('redis://default:test-only@redis:6379/messages/baander'),
        new App\Shared\Infrastructure\Messenger\JsonTransportSerializer(new App\Shared\Infrastructure\Messaging\JsonMessageCodec([new WorkloadCodec()])),
    );
    $sent = $transport->send(new Symfony\Component\Messenger\Envelope(new Workload('observation-gap', 0)));
    $messageId = $sent->last(Symfony\Component\Messenger\Stamp\TransportMessageIdStamp::class)?->getId();
    if (!is_string($messageId)) { throw new RuntimeException('Real transport did not identify gap message.'); }
    $publishedAt = hrtime(true) / 1e9;
    usleep(1_200_000);
    $staleAt = hrtime(true) / 1e9;
    $stale = $status->snapshot($staleAt);
    if ($stale['state'] !== 'unavailable' || $stale['controllerFresh'] || !$stale['authorityValid'] || $completions() !== []) {
        throw new RuntimeException('Stale controller admitted work or remained falsely available.');
    }
    $redis = new Redis();
    $redis->connect('redis', 6379, .2, read_timeout: .2);
    $redis->auth(['default', 'test-only']);
    $proof = $redis->eval(<<<'LUA'
        local groups = redis.call('XINFO', 'GROUPS', KEYS[1])
        local last = nil
        for _, group in ipairs(groups) do
            local name, candidate = '', ''
            for i=1,#group,2 do
                if group[i] == 'name' then name=group[i+1] end
                if group[i] == 'last-delivered-id' then candidate=group[i+1] end
            end
            if name == ARGV[1] then last=candidate end
        end
        if not last then return redis.error_reply('missing admitted group') end
        local function less(a,b)
            local am,as=string.match(a,'^(%d+)%-(%d+)$')
            local bm,bs=string.match(b,'^(%d+)%-(%d+)$')
            return tonumber(am)<tonumber(bm) or (tonumber(am)==tonumber(bm) and tonumber(as)<tonumber(bs))
        end
        return {#redis.call('XRANGE',KEYS[1],ARGV[2],ARGV[2]),
            #redis.call('XPENDING',KEYS[1],ARGV[1],ARGV[2],ARGV[2],1),last,less(last,ARGV[2]) and 1 or 0}
        LUA, ['messages', 'baander', $messageId], 1);
    if (!is_array($proof) || $proof[0] !== 1 || $proof[1] !== 0 || $proof[3] !== 1) {
        throw new RuntimeException('Gap message was consumed while controller was stale.');
    }
    $staleHealth = $healthy(hrtime(true) / 1e9);
} finally {
    $resumedAt = hrtime(true) / 1e9;
    $resume();
}
$recoverDeadline = min($deadline, $resumedAt + 12);
do {
    $now = hrtime(true) / 1e9;
    $recovered = $status->snapshot($now);
    $completed = $completions();
    if ($recovered['state'] === 'known' && $recovered['authorityValid'] && $recovered['controllerFresh'] && count($completed) === 1) { break; }
    usleep(50000);
} while ($now < $recoverDeadline);
if ($now >= $recoverDeadline || count($completed) !== 1 || $completed[0]['sequence'] !== 0 || $completed[0]['started'] < $resumedAt) {
    throw new RuntimeException('Fresh observation did not recover the single parked delivery within twelve seconds.');
}
$recoveredHealth = $healthy(hrtime(true) / 1e9);
if ($startTicks($controller['pid']) !== $controller['startTicks']) { throw new RuntimeException('Autoscaler identity changed during observation gap.'); }
echo json_encode(['namespace' => $namespace, 'bootId' => $boot, 'epoch' => $lease->epoch, 'controller' => $controller,
    'startedAt' => $started, 'stoppedAt' => $stoppedAt, 'publishedAt' => $publishedAt, 'staleAt' => $staleAt,
    'resumedAt' => $resumedAt, 'recoveredAt' => $now, 'maxSampleAgeSeconds' => $profile->maxSampleAgeMs / 1000,
    'messageId' => $messageId, 'unreadProof' => ['present' => $proof[0], 'pending' => $proof[1], 'lastDeliveredId' => $proof[2], 'unread' => $proof[3]],
    'staleStatus' => $stale, 'staleHealth' => $staleHealth, 'recoveredStatus' => $recovered,
    'recoveredHealth' => $recoveredHealth, 'completion' => $completed[0], 'finishedAt' => hrtime(true) / 1e9], JSON_THROW_ON_ERROR) . "\n";
