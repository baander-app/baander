<?php

declare(strict_types=1);

// Runs the application's 1080p hardware encode command alone and at increasing concurrency
// inside the worker image, with the configured device passed through, and writes one JSON
// line per event to stdout: a header, then for every step its start, per-process and GPU
// samples, and its end. Encodes start the way the native daemon starts them. The source is
// a 1080p file that each encode decodes, scales and encodes on the device.
// Arguments: device, seconds a step may run, encoders (comma-separated), concurrency steps
// (comma-separated), work directory.

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Infrastructure\Worker\NativeExecutionDaemon;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\FFprobeArguments;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use Symfony\Component\VarExporter\ProxyHelper;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const SOURCE_SECONDS = 120;
const SOURCE_FRAME_RATE = 30;
const SAMPLE_INTERVAL_MICROSECONDS = 500_000;

[$device, $seconds, $encoders, $steps, $work] = [$argv[1] ?? '/dev/dri/renderD128', (float) ($argv[2] ?? 150),
    explode(',', $argv[3] ?? 'h264_vaapi,hevc_vaapi'), array_map(intval(...), explode(',', $argv[4] ?? '1,2,4,8,12,16,24')), $argv[5] ?? '/work'];
$emit = static function (array $record): void {
    fwrite(STDOUT, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
};
$read = static fn (string $path, int $length = 65_536): ?string => is_string($value = @file_get_contents($path, false, null, 0, $length)) ? $value : null;
$run = static function (array $command): string {
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start ' . $command[0]);
    }
    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException($command[0] . ' failed: ' . substr($error, 0, 2000));
    }
    return $output;
};

// The application's 1080p source recipe: a textured 1080p30 H.264 picture with AAC audio.
$source = $work . '/cost-1080p.mkv';
if (!is_file($source)) {
    $run(['ffmpeg', '-nostdin', '-v', 'error', '-y',
        '-f', 'lavfi', '-i', 'testsrc2=size=1920x1080:rate=' . SOURCE_FRAME_RATE . ':duration=' . SOURCE_SECONDS . ',noise=alls=3:allf=t',
        '-f', 'lavfi', '-i', 'sine=frequency=330:sample_rate=48000:duration=' . SOURCE_SECONDS,
        '-map', '0:v', '-map', '1:a', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21', '-g', '120', '-threads', '8',
        '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '160k', '-ac', '2', $source]);
}
$probe = VideoProbeResult::fromProbeOutput(json_decode($run(FFprobeArguments::video($source, 'ffprobe')), true, flags: JSON_THROW_ON_ERROR));

// Argument building touches neither port; each stub refuses every call.
$stub = static function (string $interface): object {
    $methods = '';
    foreach ((new ReflectionClass($interface))->getMethods() as $method) {
        $methods .= ProxyHelper::exportSignature($method) . " { throw new \\LogicException('Encoder arguments do not use this port.'); }\n";
    }
    return eval('return new class implements \\' . $interface . " {\n" . $methods . '};');
};
$ffmpeg = $stub(App\Transcode\Application\Port\FFmpegPortInterface::class);
$storage = $stub(App\Transcode\Application\Port\TranscodeStoragePortInterface::class);
$tier = QualityTier::p1080();
$audioProfile = AudioProfile::streamingStereo();
$command = static function (string $encoder, string $output) use ($device, $ffmpeg, $storage, $probe, $tier, $audioProfile, $source): array {
    $segments = new SegmentEncoder(new MediaExecutionPolicy(new DeploymentProfile('full')), $ffmpeg, $storage, EncoderProfile::fromEncoderName($encoder, $device));
    return $segments->buildStreamArgs(
        $source,
        $tier,
        $segments->buildVideoFilters($probe, $tier),
        $output,
        audioStreamIndex: $probe->selectedAudioStreamOrdinal() ?? 0,
        audioProfile: $audioProfile,
        audioFilters: $segments->buildAudioFiltersForProfile($probe, $audioProfile),
        hasAudio: $probe->hasAudio(),
        sourceFrameRate: $probe->framerate,
        sourceTimestampOrigin: $probe->sourceTimestampOrigin,
    );
};

// The render node's sysfs device carries the GPU's busy and memory counters.
$sysfs = '/sys/class/drm/' . basename($device) . '/device';
$gpu = static function () use ($sysfs, $read): array {
    $value = static fn (string $name): ?int => is_string($raw = $read($sysfs . '/' . $name)) && is_numeric(trim($raw)) ? (int) trim($raw) : null;
    return ['busyPercent' => $value('gpu_busy_percent'), 'vcnBusyPercent' => $value('vcn_busy_percent'),
        'memoryBusyPercent' => $value('mem_busy_percent'), 'vramUsedBytes' => $value('mem_info_vram_used')];
};
$models = [];
preg_match_all('/^model name\s*:\s*(.+)$/m', (string) $read('/proc/cpuinfo', 1_048_576), $models);
$emit(['header' => [
    'clockTicks' => 100,
    'cpuModel' => $models[1][0] ?? null,
    'hostLogicalCpus' => count($models[1]),
    'kernel' => trim((string) $read('/proc/sys/kernel/osrelease')),
    'ffmpeg' => strtok($run(['ffmpeg', '-hide_banner', '-version']), "\n"),
    'device' => $device,
    'gpu' => ['vendor' => trim((string) $read($sysfs . '/vendor')), 'device' => trim((string) $read($sysfs . '/device')),
        'vramTotalBytes' => is_string($total = $read($sysfs . '/mem_info_vram_total')) ? (int) trim($total) : null],
    'source' => ['path' => $source, 'seconds' => SOURCE_SECONDS, 'frameRate' => SOURCE_FRAME_RATE, 'width' => $probe->width, 'height' => $probe->height, 'codec' => $probe->videoCodec],
    'tier' => $tier->name,
    'encoders' => $encoders,
    'steps' => $steps,
    'stepSeconds' => $seconds,
    'memAvailableKib' => preg_match('/^MemAvailable:\s+(\d+) kB$/m', (string) $read('/proc/meminfo'), $match) === 1 ? (int) $match[1] : null,
]]);

/** CPU ticks, start time, threads, memory, progress and per-engine GPU time of one process. */
$sample = static function (int $pid, string $progress) use ($read, $device): ?array {
    $stat = $read('/proc/' . $pid . '/stat');
    $status = $read('/proc/' . $pid . '/status');
    if ($stat === null || $status === null) {
        return null;
    }
    $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
    $memory = static fn (string $key): ?int => preg_match('/^' . $key . ':\s+(\d+) kB$/m', $status, $match) === 1 ? (int) $match[1] : null;
    $values = [];
    foreach (explode("\n", (string) $read($progress)) as $line) {
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $value;
        }
    }
    // A DRM client is one opened file description; duplicated descriptors report it again.
    $engines = [];
    $clients = [];
    foreach (glob('/proc/' . $pid . '/fd/*') ?: [] as $descriptor) {
        if (@readlink($descriptor) !== $device) {
            continue;
        }
        $info = (string) $read('/proc/' . $pid . '/fdinfo/' . basename($descriptor));
        if (preg_match('/^drm-client-id:\s+(\d+)$/m', $info, $client) !== 1 || isset($clients[$client[1]])) {
            continue;
        }
        $clients[$client[1]] = true;
        preg_match_all('/^drm-engine-([a-z0-9_-]+):\s+(\d+) ns$/m', $info, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $engine, $nanoseconds]) {
            $engines[$engine] = ($engines[$engine] ?? 0) + (int) $nanoseconds;
        }
        if (preg_match('/^drm-memory-vram:\s+(\d+) KiB$/m', $info, $vram) === 1) {
            $engines['vramKib'] = ($engines['vramKib'] ?? 0) + (int) $vram[1];
        }
    }
    return [
        'id' => $pid . ':' . $fields[19],
        'ppid' => (int) $fields[1],
        'startTicks' => (int) $fields[19],
        'cpuTicks' => (int) $fields[11] + (int) $fields[12],
        'threads' => (int) $fields[17],
        'rssKib' => $memory('VmRSS'),
        'hwmKib' => $memory('VmHWM'),
        'progress' => array_intersect_key($values, array_flip(['frame', 'out_time_us', 'speed', 'progress'])),
        'gpu' => $engines,
    ];
};

foreach ($encoders as $encoder) {
    foreach ($steps as $concurrency) {
        $processes = [];
        $directories = [];
        $arguments = null;
        foreach (range(1, $concurrency) as $index) {
            $directory = $work . '/out/' . $encoder . '-' . $concurrency . '-' . $index;
            if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
                throw new RuntimeException('Cannot create encode output.');
            }
            $directories[] = $directory;
            $arguments = $command($encoder, $directory);
            $progress = $directory . '/progress.txt';
            $launched = [...NativeExecutionDaemon::launcher(), $arguments[0], '-progress', $progress, '-stats_period', '0.5', ...array_slice($arguments, 1)];
            $process = proc_open($launched, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $directory . '/stderr.log', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Encoder did not start.');
            }
            $processes[] = ['process' => $process, 'pid' => proc_get_status($process)['pid'], 'progress' => $progress];
        }
        $startedAt = hrtime(true) / 1e9;
        $emit(['step' => ['encoder' => $encoder, 'concurrency' => $concurrency, 'startedAt' => $startedAt, 'arguments' => $arguments]]);
        $deadline = $startedAt + $seconds;
        $exits = [];
        while (true) {
            $now = hrtime(true) / 1e9;
            $records = [];
            foreach ($processes as $key => $entry) {
                if (isset($exits[$key])) {
                    continue;
                }
                $record = $sample($entry['pid'], $entry['progress']);
                if ($record !== null) {
                    $records[] = $record;
                }
            }
            $emit(['sample' => ['t' => $now, 'uptime' => (float) strtok((string) $read('/proc/uptime'), ' '), 'loadavg' => (float) strtok((string) $read('/proc/loadavg'), ' '),
                'processes' => $records, 'gpu' => $gpu()]]);
            foreach ($processes as $key => $entry) {
                $status = proc_get_status($entry['process']);
                if (!$status['running'] && !isset($exits[$key])) {
                    $exits[$key] = $status['exitcode'];
                }
            }
            if (count($exits) === count($processes) || $now >= $deadline) {
                break;
            }
            usleep(SAMPLE_INTERVAL_MICROSECONDS);
        }
        $endedAt = hrtime(true) / 1e9;
        foreach ($processes as $key => $entry) {
            if (!isset($exits[$key])) {
                proc_terminate($entry['process'], SIGTERM);
            }
        }
        $stopped = [];
        foreach ($processes as $key => $entry) {
            $grace = hrtime(true) / 1e9 + 10;
            while (!isset($exits[$key]) && proc_get_status($entry['process'])['running'] && hrtime(true) / 1e9 < $grace) {
                usleep(50_000);
            }
            if (!isset($exits[$key]) && proc_get_status($entry['process'])['running']) {
                proc_terminate($entry['process'], SIGKILL);
            }
            proc_close($entry['process']);
            $stopped[] = ['exitCode' => $exits[$key] ?? null, 'stoppedAtDeadline' => !isset($exits[$key]),
                'stderr' => substr((string) $read($directories[$key] . '/stderr.log'), 0, 2000)];
        }
        $emit(['end' => ['encoder' => $encoder, 'concurrency' => $concurrency, 'endedAt' => $endedAt, 'processes' => $stopped]]);
        foreach ($directories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
