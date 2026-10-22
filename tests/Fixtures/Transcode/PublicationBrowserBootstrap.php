<?php

declare(strict_types=1);

use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use PHPUnit\Framework\MockObject\Generator\Generator;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$directory = getenv('BAANDER_PROGRESSIVE_DIRECTORY');
if ($directory === false || (!is_dir($directory) && !mkdir($directory, 0750, true))) {
    throw new RuntimeException('A disposable publication directory is required.');
}
$writeJson = static function (string $name, array $value) use ($directory): void {
    $path = $directory . '/' . $name . '.json';
    file_put_contents($path . '.part', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    rename($path . '.part', $path);
};
$run = static function (array $arguments): string {
    $process = new Process($arguments, timeout: 30);
    $process->mustRun();
    return $process->getOutput();
};
$version = strtok($run(['/usr/bin/ffmpeg', '-version']), "\n");
if (!is_string($version) || !str_starts_with($version, 'ffmpeg version 5.')) {
    throw new RuntimeException('This qualification requires the real FFmpeg 5 producer: ' . (string) $version);
}
$source = $directory . '/source.mp4';
$run(['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-filter_threads', '1', '-filter_complex_threads', '1',
    '-f', 'lavfi', '-i', 'testsrc2=size=1280x720:rate=24',
    '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000', '-t', '20', '-c:v', 'libx264',
    '-threads', '2', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-g', '24', '-c:a', 'aac', $source]);
$probe = json_decode($run(['/usr/bin/ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $source]), true, 512, JSON_THROW_ON_ERROR);
$expectedDuration = (float) $probe['format']['duration'];
$videoStream = array_values(array_filter($probe['streams'], static fn (array $stream): bool => $stream['codec_type'] === 'video'))[0];
[$rateNumerator, $rateDenominator] = array_map('intval', explode('/', $videoStream['r_frame_rate']));
$frameRate = $rateNumerator / $rateDenominator;

// Command construction does not call either port. Encoding and delivery below
// use the real executable and production presentation reader and generators.
$generator = new Generator();
$ffmpegPort = $generator->testDouble(FFmpegPortInterface::class, false);
$storagePort = $generator->testDouble(TranscodeStoragePortInterface::class, false);
assert($ffmpegPort instanceof FFmpegPortInterface);
assert($storagePort instanceof TranscodeStoragePortInterface);
$encoder = new SegmentEncoder($ffmpegPort, $storagePort, EncoderProfile::software('libx264'));
$arguments = $encoder->buildStreamArgs($source, QualityTier::p720(), '', $directory,
    audioFilters: 'aformat=channel_layouts=stereo', hasAudio: true);
$input = array_search('-i', $arguments, true);
if ($input === false) {
    throw new RuntimeException('The producer command omitted its source.');
}
array_splice($arguments, $input, 0, ['-re']);
$writeJson('producer-command', ['arguments' => $arguments, 'ffmpegVersion' => $version]);

$socket = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
if ($socket === false) {
    throw new RuntimeException($message, $number);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
if ($address === false) {
    throw new RuntimeException('No disposable HTTP address was assigned.');
}
$port = (int) substr($address, strrpos($address, ':') + 1);
$origin = 'http://publication.baander.app:' . $port;
$unsigned = '/api/transcode/' . str_repeat('V', 21) . '/manifest.mpd?set=' . str_repeat('J', 21) . '.1';
$manifest = (new HmacStreamAuthAdapter('disposable-baander-publication-fixture', $origin))->signUrl($unsigned)['url'];
$server = new Process([PHP_BINARY, '-S', $address, __DIR__ . '/PublicationBrowserRouter.php'], timeout: null);
$producer = null;
$stopping = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$stopping): void { $stopping = true; });
pcntl_signal(SIGINT, static function () use (&$stopping): void { $stopping = true; });
try {
    $server->start(static function (string $type, string $bytes) use ($directory): void {
        file_put_contents($directory . '/server.log', $bytes, FILE_APPEND);
    });
    $deadline = microtime(true) + 10;
    do {
        $connection = @stream_socket_client('tcp://' . $address, $errorNumber, $errorMessage, 0.1);
        if ($connection !== false) {
            fclose($connection);
            break;
        }
        if (!$server->isRunning() || microtime(true) >= $deadline) {
            throw new RuntimeException('The disposable publication server did not start.');
        }
        usleep(50_000);
    } while (true);
    $writeJson('ready', ['origin' => $origin, 'manifestUrl' => $manifest, 'expectedDuration' => $expectedDuration,
        'frameRate' => $frameRate, 'width' => $videoStream['width'], 'height' => $videoStream['height'], 'ffmpegVersion' => $version]);
    $lastIndex = null;
    while (!$stopping) {
        if (!$server->isRunning()) {
            throw new RuntimeException('The disposable publication server stopped unexpectedly.');
        }
        if ($producer === null && is_file($directory . '/start')) {
            $producer = new Process($arguments, timeout: 45);
            $producer->start(static function (string $type, string $bytes) use ($directory): void {
                file_put_contents($directory . '/producer.log', $bytes, FILE_APPEND);
            });
        }
        $running = $producer?->isRunning() ?? false;
        $writeJson('producer', ['started' => $producer !== null, 'running' => $running,
            'exitCode' => $producer !== null && !$running ? $producer->getExitCode() : null]);
        $native = $directory . '/manifest.mpd';
        if (is_file($native)) {
            $index = file_get_contents($native);
            if ($index !== false && $index !== $lastIndex) {
                file_put_contents($directory . '/native-indexes.jsonl', json_encode(['time' => microtime(true),
                    'producerRunning' => $running, 'mpd' => $index], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
                $lastIndex = $index;
            }
        }
        $producer?->checkTimeout();
        usleep(100_000);
    }
} finally {
    $producer?->stop(2);
    $server->stop(2);
}
