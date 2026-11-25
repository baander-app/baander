<?php

declare(strict_types=1);
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Worker\Autoscaling\AutoscalingStatus;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use Baander\DevQualification\Fixtures\Worker\AutoscalingStatusObservation;
use Baander\DevQualification\Fixtures\Worker\CompletionCursor;
use Baander\DevQualification\Fixtures\Worker\EvidenceMode;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/scripts/vendor/autoload.php';

$unit = $argv[1] ?? '';
$seconds = (int) ($argv[2] ?? 480);
if (!in_array($unit, ['control', 'execution'], true) || $seconds < 1 || $seconds > 500) {
    throw new InvalidArgumentException('Invalid bounded sampler.');
}
$db = $unit === 'control' ? DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse((string) getenv('DATABASE_URL'))) : null;
$directory = (getenv('BAANDER_WORKER_IPC_DIRECTORY') ?: '/run/baander-worker') . '/' . getenv('BAANDER_WORKER_BOOT_ID');
$completions = new CompletionCursor($directory . '/autoscaling-completions.jsonl');
$deadline = hrtime(true) / 1e9 + $seconds;
// The figures an unlimited cgroup is measured against: the host's CPUs, memory and task bound.
$kernel = static fn(string $name): int => (int) trim((string) @file_get_contents('/proc/sys/kernel/' . $name));
$host = [
    'cpus' => preg_match_all('/^cpu[0-9]+ /m', (string) @file_get_contents('/proc/stat')),
    'memoryBytes' => preg_match('/^MemTotal:\s+([0-9]+) kB$/m', (string) @file_get_contents('/proc/meminfo'), $total) === 1 ? (int) $total[1] * 1024 : null,
    'tasks' => min($kernel('pid_max'), $kernel('threads-max')),
];
while (true) {
    $stopping = hrtime(true) / 1e9 >= $deadline || (($stopFile = getenv('BAANDER_TEST_SAMPLER_STOP_FILE')) !== false && is_file($stopFile));
    $sample = ['monotonic' => hrtime(true) / 1e9, 'wallTime' => microtime(true), 'unit' => $unit, 'host' => $host];
    foreach (['cpu.stat', 'memory.current', 'memory.events', 'memory.peak', 'pids.current', 'pids.events', 'cpuset.cpus.effective', 'cpu.max', 'memory.max', 'pids.max'] as $name) {
        $sample[$name] = @file_get_contents('/sys/fs/cgroup/' . $name);
    }
    foreach (['admission', 'resource', 'control', 'execution'] as $name) {
        $raw = @file_get_contents($directory . '/' . $name . '.json', false, null, 0, 32769);
        $sample[$name] = is_string($raw) && strlen($raw) <= 32768 ? json_decode($raw, true) : null;
    }
    if (is_array($sample['admission'])) {
        $state = $sample['admission'];
        $profileFields = json_decode((string) file_get_contents(__DIR__ . '/autoscaling-profile.json'), true, flags: JSON_THROW_ON_ERROR);
        $profileFields['maximumConcurrency'] = count($state['slots']);
        $profile = WorkerAutoscalingProfile::fromJson(json_encode($profileFields, JSON_THROW_ON_ERROR));
        $lease = new DeploymentLease($state['namespace'], $state['bootId'], $state['epoch']);
        $status = new AutoscalingStatus(dirname($directory), $lease, $profile, DeploymentProfile::fromEnvironment());
        $observation = AutoscalingStatusObservation::capture($status->snapshot(...));
        $sample['status'] = $observation['status'];
        $sample['statusAttempts'] = $observation['attempts'];
    }
    $sample['processes'] = [];
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || ($end = strrpos($raw, ') ')) === false) {
            continue;
        }
        $fields = explode(' ', substr($raw, $end + 2));
        $arguments = @file_get_contents(dirname($path) . '/cmdline');
        $status = @file_get_contents(dirname($path) . '/status');
        $again = @file_get_contents($path);
        if (!is_string($again) || ($againEnd = strrpos($again, ') ')) === false
            || (explode(' ', substr($again, $againEnd + 2))[19] ?? null) !== $fields[19]) {
            continue;
        }
        $rssKiB = is_string($status) && preg_match('/^VmRSS:\s+([0-9]+) kB$/m', $status, $match) === 1 ? (int) $match[1] : null;
        $score = @file_get_contents(dirname($path) . '/oom_score_adj');
        $sample['processes'][] = ['pid' => (int) basename(dirname($path)), 'userTicks' => $fields[11], 'systemTicks' => $fields[12],
            'startTicks' => $fields[19], 'rssPages' => $fields[21], 'rssBytes' => $rssKiB === null ? null : $rssKiB * 1024, 'cmdline' => $arguments,
            'nice' => (int) $fields[16], 'oomScoreAdj' => is_string($score) ? (int) trim($score) : null];
    }
    if ($db !== null) {
        $sample['lease'] = $db->fetchAssociative('SELECT owner_boot_id, epoch, state, expires_at > clock_timestamp() AS unexpired FROM worker_deployment_leases WHERE namespace = ?', [getenv('BAANDER_WORKER_NAMESPACE')]);
        $sample['connections'] = (int) $db->fetchOne('SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()');
        $sample['completions'] = $completions->read($stopping ? EvidenceMode::Completed : EvidenceMode::Live);
    }
    $sample['capturedAt'] = hrtime(true) / 1e9;
    echo json_encode($sample, JSON_THROW_ON_ERROR) . "\n";
    flush();
    if ($stopping) {
        break;
    }
    usleep(AutoscalingStatusObservation::INTERVAL_MICROSECONDS);
}
