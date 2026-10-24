<?php

declare(strict_types=1);

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\TranscodeRenditionSet;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use PHPUnit\Framework\MockObject\Generator\Generator;
use Symfony\Component\Process\Process;

require __DIR__ . '/AdaptiveBrowserFixture.php';

$directory = AdaptiveBrowserFixture::directory();
$audioProfile = match (getenv('BAANDER_ADAPTIVE_AUDIO_PROFILE') ?: 'streaming_stereo') {
    'streaming_stereo' => AudioProfile::streamingStereo(),
    'opus_stereo' => AudioProfile::opusStereo(),
    default => throw new RuntimeException('The adaptive fixture supports only streaming_stereo and opus_stereo.'),
};
if (is_file($directory . '/state.serialized') || is_file($directory . '/ready.json')) {
    throw new RuntimeException('Use a fresh disposable adaptive directory for each qualification.');
}
$run = static function (array $arguments, float $timeout = 60): string {
    $process = new Process($arguments, timeout: $timeout);
    $process->mustRun();
    return $process->getOutput();
};
$version = strtok($run(['/usr/bin/ffmpeg', '-version']), "\n");
if (!is_string($version) || !str_starts_with($version, 'ffmpeg version 5.')) {
    throw new RuntimeException('The browser qualification requires FFmpeg 5: ' . (string) $version);
}
$source = $directory . '/source.mp4';
$run(['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-filter_threads', '1', '-filter_complex_threads', '1',
    '-f', 'lavfi', '-i', 'testsrc2=size=1280x720:rate=24', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
    '-t', '144', '-c:v', 'libx264', '-threads', '2', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-g', '24', '-c:a', 'aac', $source]);
$rawProbe = json_decode($run(['/usr/bin/ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $source]), true, 512, JSON_THROW_ON_ERROR);
$probe = VideoProbeResult::fromProbeOutput($rawProbe);
$generator = new Generator();
$ffmpegPort = $generator->testDouble(FFmpegPortInterface::class, false);
$storagePort = $generator->testDouble(TranscodeStoragePortInterface::class, false);
assert($ffmpegPort instanceof FFmpegPortInterface);
assert($storagePort instanceof TranscodeStoragePortInterface);
$encoder = new SegmentEncoder($ffmpegPort, $storagePort, EncoderProfile::software('libx264'));
$origin = 'http://adaptive.baander.app:18778';
$state = ['origin' => $origin, 'videoId' => Uuid::generate(), 'videoPublicId' => new PublicId(),
    'audioProfile' => $audioProfile->name, 'jobs' => []];
$owner = Uuid::generate();
$commands = $readyTiers = $pairs = [];
foreach (['low' => QualityTier::p360(), 'high' => QualityTier::p720()] as $label => $tier) {
    $job = TranscodeJob::create($state['videoId'], $tier, $directory, $owner);
    $output = $directory . '/' . $state['videoId'] . '/' . $tier->name . '/job-' . $job->getId() . '/generation-1';
    mkdir($output, 0750, true);
    AdaptiveBrowserFixture::writeJson($output . '/generation.json', ['schemaVersion' => 2, 'generation' => 1,
        'videoCodec' => 'h264', 'videoEncoder' => 'libx264', 'audioCodec' => $audioProfile->codec,
        'audioProfile' => $audioProfile->jsonSerialize(), 'duration' => $probe->duration,
        'startTime' => 0, 'nativeAvailabilityStartTime' => gmdate('Y-m-d\TH:i:s\Z')]);
    $arguments = $encoder->buildStreamArgs($source, $tier, $encoder->buildVideoFilters($probe, $tier), $output,
        audioProfile: $audioProfile, audioFilters: $encoder->buildAudioFiltersForProfile($probe, $audioProfile), hasAudio: true);
    $input = array_search('-i', $arguments, true);
    if ($input === false) {
        throw new RuntimeException('The encoder omitted its source.');
    }
    array_splice($arguments, $input, 0, ['-re']);
    $commands[$tier->name] = $arguments;
    $state['jobs'][$tier->name] = ['job' => $job, 'sessionId' => Uuid::generate(), 'directory' => $output];
    $readyTiers[$label] = ['name' => $tier->name, 'jobId' => $job->getPublicId()->toString(), 'width' => $tier->width, 'height' => $tier->height];
    $pairs[] = ['jobId' => $job->getPublicId()->toString(), 'generation' => 1];
}
file_put_contents($directory . '/state.serialized', serialize($state));
AdaptiveBrowserFixture::writeJson($directory . '/producer-commands.json', $commands);
$set = TranscodeRenditionSet::encode($pairs);
$manifest = (new HmacStreamAuthAdapter('disposable-baander-adaptive-fixture', $origin))->signUrl(
    '/api/transcode/' . $state['videoPublicId'] . '/master.m3u8?' . http_build_query(['set' => $set]))['url'];
$server = new Process([PHP_BINARY, '-S', '0.0.0.0:18778', __DIR__ . '/AdaptiveBrowserRouter.php'], timeout: null);
$producers = [];
$stopping = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$stopping): void { $stopping = true; });
pcntl_signal(SIGINT, static function () use (&$stopping): void { $stopping = true; });
$deadline = microtime(true) + 240;
try {
    $server->start(static function (string $type, string $bytes) use ($directory): void {
        file_put_contents($directory . '/server.log', $bytes, FILE_APPEND);
    });
    $serverDeadline = microtime(true) + 10;
    do {
        $connection = @stream_socket_client('tcp://127.0.0.1:18778', $errorNumber, $errorMessage, 0.1);
        if ($connection !== false) {
            fclose($connection);
            break;
        }
        if (!$server->isRunning() || microtime(true) >= $serverDeadline) {
            throw new RuntimeException('The adaptive HTTP server failed to start.');
        }
        usleep(50_000);
    } while (true);
    AdaptiveBrowserFixture::writeJson($directory . '/ready.json', ['origin' => $origin, 'manifestUrl' => $manifest,
        'expectedDuration' => $probe->duration, 'frameRate' => $probe->framerate, 'ffmpegVersion' => $version,
        'audioProfile' => $audioProfile->name,
        'tiers' => $readyTiers, 'canonicalAudioJobId' => $readyTiers['low']['jobId']]);
    $startedAt = null;
    while (!$stopping && microtime(true) < $deadline) {
        if (!$server->isRunning()) {
            throw new RuntimeException('The adaptive HTTP server stopped unexpectedly.');
        }
        if ($startedAt === null && is_file($directory . '/start')) {
            $startedAt = microtime(true);
        }
        foreach ($commands as $name => $arguments) {
            if (!isset($producers[$name]) && $startedAt !== null && microtime(true) >= $startedAt + ($name === '720p' ? 6 : 0)) {
                $producers[$name] = new Process($arguments, timeout: 174);
                $producers[$name]->start(static function (string $type, string $bytes) use ($directory, $name): void {
                    file_put_contents($directory . '/producer-' . $name . '.log', $bytes, FILE_APPEND);
                });
            }
            $producer = $producers[$name] ?? null;
            $running = $producer?->isRunning() ?? false;
            AdaptiveBrowserFixture::writeJson($directory . '/producer-' . $name . '.json', ['running' => $running,
                'exitCode' => $producer !== null && !$running ? $producer->getExitCode() : null]);
            $producer?->checkTimeout();
        }
        usleep(100_000);
    }
} finally {
    foreach ($producers as $producer) {
        $producer->stop(2);
    }
    $server->stop(2);
}
