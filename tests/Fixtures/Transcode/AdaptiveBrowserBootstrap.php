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
use App\Transcode\Infrastructure\FFmpeg\FFmpegArguments;
use App\Transcode\Infrastructure\FFmpeg\FFprobeArguments;
use App\Transcode\Infrastructure\HLS\WebVttTimeline;
use PHPUnit\Framework\MockObject\Generator\Generator;
use Symfony\Component\Process\Process;

require __DIR__ . '/AdaptiveBrowserFixture.php';

$scenario = getenv('BAANDER_ADAPTIVE_SCENARIO') ?: 'adaptive';
if (!in_array($scenario, ['adaptive', 'sparse-fresh', 'sparse-seek', 'matroska-delayed-fresh', 'matroska-delayed-seek', 'ordinary-delayed-fresh', 'ordinary-delayed-seek', 'default-audio-fresh', 'default-audio-seek', 'short-audio-fresh', 'short-audio-seek'], true)) {
    throw new RuntimeException('Unknown adaptive browser scenario: ' . $scenario);
}
$matroska = str_starts_with($scenario, 'matroska-delayed-');
$defaultAudio = str_starts_with($scenario, 'default-audio-');
$shortAudio = str_starts_with($scenario, 'short-audio-');
$ordinary = $shortAudio || $defaultAudio || str_starts_with($scenario, 'ordinary-delayed-');
$sparse = $matroska || str_starts_with($scenario, 'sparse-');
$delayedAudio = $matroska || ($ordinary && !$shortAudio);
$hasSubtitles = $matroska;
$sourceStartTime = str_ends_with($scenario, '-seek') ? 6 : 0;
$directory = AdaptiveBrowserFixture::directory();
$audioProfile = match (getenv('BAANDER_ADAPTIVE_AUDIO_PROFILE') ?: 'streaming_stereo') {
    'streaming_stereo' => AudioProfile::streamingStereo(),
    'opus_stereo' => AudioProfile::opusStereo(),
    default => throw new RuntimeException('The adaptive fixture supports only streaming_stereo and opus_stereo.'),
};
if ($scenario !== 'adaptive' && $audioProfile->name !== 'streaming_stereo') {
    throw new RuntimeException('Movie qualification scenarios require streaming_stereo audio.');
}
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
if ($shortAudio) {
    $source = $directory . '/source.mov';
    $run(['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-filter_threads', '1',
        '-f', 'lavfi', '-i', 'color=c=red:size=1280x720:rate=25,drawbox=color=green:t=fill:enable=gte(t\,10),drawbox=color=blue:t=fill:enable=gte(t\,20)',
        '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000:duration=4',
        '-map', '0:v', '-map', '1:a', '-t', '30', '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast',
        '-pix_fmt', 'yuv420p', '-bf', '0', '-g', '25', '-c:a', 'pcm_s16le', $source]);
} elseif ($defaultAudio) {
    $source = $directory . '/source.mov';
    $dialogue = implode('|', array_fill(0, 6, '0.05*sin(2*PI*880*t)'));
    $run(['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-filter_threads', '1',
        '-f', 'lavfi', '-i', 'color=c=red:size=1280x720:rate=25,drawbox=color=green:t=fill:enable=gte(t\,10),drawbox=color=blue:t=fill:enable=gte(t\,20)',
        '-f', 'lavfi', '-i', 'sine=frequency=220:sample_rate=44100:duration=30',
        '-itsoffset', '8', '-f', 'lavfi', '-i', 'aevalsrc=' . $dialogue . ':s=48000:d=22:c=5.1(side)',
        '-map', '0:v', '-map', '1:a', '-map', '2:a', '-t', '30', '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast',
        '-pix_fmt', 'yuv420p', '-bf', '0', '-g', '25', '-c:a', 'pcm_s16le',
        '-disposition:a:0', '0', '-disposition:a:1', 'default', '-metadata:s:a:0', 'language=eng', '-metadata:s:a:1', 'language=dan', $source]);
} elseif ($ordinary) {
    $source = $directory . '/source.mov';
    $run(['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-filter_threads', '1',
        '-f', 'lavfi', '-i', 'color=c=red:size=1280x720:rate=25,drawbox=color=green:t=fill:enable=gte(t\,10),drawbox=color=blue:t=fill:enable=gte(t\,20)',
        '-itsoffset', '8', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=22',
        '-map', '0:v', '-map', '1:a', '-t', '30', '-c:v', 'libx264', '-threads', '2', '-preset', 'ultrafast',
        '-pix_fmt', 'yuv420p', '-bf', '0', '-g', '25', '-c:a', 'pcm_s16le', $source]);
} elseif ($matroska) {
    $captions = $directory . '/captions.srt';
    file_put_contents($captions, "1\n00:00:02,000 --> 00:00:04,000\nEarly source cue\n\n2\n00:00:12,000 --> 00:00:14,000\nLater source cue\n");
    $base = $directory . '/source-zero.mkv';
    $run(['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-filter_threads', '1',
        '-f', 'lavfi', '-i', 'color=c=red:size=1280x720:rate=1/10,drawbox=color=green:t=fill:enable=gte(t\,10),drawbox=color=blue:t=fill:enable=gte(t\,20)',
        '-itsoffset', '8', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=22',
        '-map', '0:v', '-map', '1:a', '-t', '30',
        '-c:v', 'libx264', '-threads', '2', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-bf', '0', '-g', '1',
        '-c:a', 'pcm_s16le', $base]);
    $source = $directory . '/source.mkv';
    $run(['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-copyts', '-i', $base, '-i', $captions,
        '-map', '0', '-map', '1:0', '-c', 'copy', '-output_ts_offset', '4', '-avoid_negative_ts', 'disabled',
        '-metadata:s:s:0', 'language=eng', '-disposition:s:0', 'default', $source]);
} else {
    $source = $directory . '/source.mp4';
    $run(['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-filter_threads', '1', '-filter_complex_threads', '1',
        '-f', 'lavfi', '-i', $sparse
            ? 'color=c=red:size=1280x720:rate=1/10,drawbox=color=green:t=fill:enable=gte(t\,10),drawbox=color=blue:t=fill:enable=gte(t\,20)'
            : 'testsrc2=size=1280x720:rate=24', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
        '-t', $sparse ? '30' : '144', '-c:v', 'libx264', '-threads', '2', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-g', '24', '-c:a', 'aac', $source]);
}
$probeArguments = FFprobeArguments::video($source);
$rawProbe = json_decode($run($probeArguments), true, 512, JSON_THROW_ON_ERROR);
AdaptiveBrowserFixture::writeJson($directory . '/source-probe.json', $rawProbe);
AdaptiveBrowserFixture::writeJson($directory . '/source-probe-command.json', $probeArguments);
$probe = VideoProbeResult::fromProbeOutput($rawProbe);
if ($matroska && ($probe->sourceTimestampOrigin !== 4.0 || abs($probe->duration - 30.0) > 0.01
    || abs((float) ($rawProbe['format']['duration'] ?? 0) - 34.0) > 0.01)) {
    throw new RuntimeException('The delayed Matroska fixture must verify origin four and a thirty-second source timeline.');
}
$movieStreamIndex = null;
foreach ($rawProbe['streams'] ?? [] as $stream) {
    if (($stream['codec_type'] ?? null) === 'video' && !($stream['disposition']['attached_pic'] ?? false)) {
        $movieStreamIndex = $stream['index'];
        break;
    }
}
$sourceVideoFirstPts = null;
foreach ($rawProbe['packets'] ?? [] as $packet) {
    if (($packet['stream_index'] ?? null) === $movieStreamIndex) {
        $sourceVideoFirstPts = isset($packet['pts_time']) ? (float) $packet['pts_time'] : null;
        break;
    }
}
$selectedAudioOrdinal = $probe->selectedAudioStreamOrdinal() ?? throw new RuntimeException('Browser movies must contain source audio.');
$selectedAudio = $probe->selectedAudioStream() ?? throw new RuntimeException('The selected browser movie audio must exist.');
$rawAudioStreams = array_values(array_filter($rawProbe['streams'] ?? [], static fn (array $stream): bool => ($stream['codec_type'] ?? null) === 'audio'));
if ($defaultAudio && (count($rawAudioStreams) !== 2 || $selectedAudioOrdinal !== 1
    || ($rawAudioStreams[0]['index'] ?? null) !== 1 || ($rawAudioStreams[1]['index'] ?? null) !== 2
    || (bool) ($rawAudioStreams[0]['disposition']['default'] ?? false) || !(bool) ($rawAudioStreams[1]['disposition']['default'] ?? false)
    || ($rawAudioStreams[0]['tags']['language'] ?? null) !== 'eng' || ($rawAudioStreams[0]['channels'] ?? null) !== 1
    || ($rawAudioStreams[0]['channel_layout'] ?? null) !== 'mono'
    || (int) ($rawAudioStreams[0]['sample_rate'] ?? 0) !== 44100 || (float) ($rawAudioStreams[0]['start_time'] ?? -1) !== 0.0
    || $selectedAudio->language !== 'dan' || $selectedAudio->channels !== 6 || $selectedAudio->sampleRate !== 48000
    || $selectedAudio->channelLayout !== '5.1(side)' || !$selectedAudio->isDefault
    || $probe->audioChannels !== 6 || $probe->audioSampleRate !== 48000 || $probe->audioChannelLayout !== '5.1(side)')) {
    throw new RuntimeException('The marked Danish surround dialogue must remain audio ordinal one and global stream two after mono English commentary.');
}
$sourceAudioFirstPts = null;
if ($ordinary) {
    $audioPacketArguments = ['/usr/bin/ffprobe', '-v', 'error', '-select_streams', 'a:' . $selectedAudioOrdinal, '-show_packets',
        '-read_intervals', '%+#1', '-show_entries', 'packet=stream_index,pts_time,flags', '-of', 'json', $source];
    $audioPacketProbe = json_decode($run($audioPacketArguments), true, 512, JSON_THROW_ON_ERROR);
    AdaptiveBrowserFixture::writeJson($directory . '/source-audio-packet.json', $audioPacketProbe);
    AdaptiveBrowserFixture::writeJson($directory . '/source-audio-packet-command.json', $audioPacketArguments);
    $sourceAudioFirstPts = isset($audioPacketProbe['packets'][0]['pts_time']) ? (float) $audioPacketProbe['packets'][0]['pts_time'] : null;
    if (($audioPacketProbe['packets'][0]['stream_index'] ?? null) !== $rawAudioStreams[$selectedAudioOrdinal]['index']
        || $probe->sourceTimestampOrigin !== null || $probe->framerate !== 25.0 || $sourceVideoFirstPts !== 0.0
        || $sourceAudioFirstPts !== ($shortAudio ? 0.0 : 8.0) || abs($probe->duration - 30.0) > 0.01 || $probe->subtitleStreams !== []) {
        throw new RuntimeException('The ordinary fixture must verify video zero, twenty-five fps, the intended audio start, no anchor and no subtitles.');
    }
}
$sourceAudioEnd = null;
$sourceAudioPacketCount = null;
$sourceAudioPacketsAfterStart = null;
if ($shortAudio) {
    $packetArguments = ['/usr/bin/ffprobe', '-v', 'error', '-select_streams', 'a:' . $selectedAudioOrdinal, '-show_packets',
        '-show_entries', 'packet=stream_index,pts_time,duration_time', '-of', 'json', $source];
    $packetProbe = json_decode($run($packetArguments), true, 512, JSON_THROW_ON_ERROR);
    AdaptiveBrowserFixture::writeJson($directory . '/source-audio-all-packets.json', $packetProbe);
    AdaptiveBrowserFixture::writeJson($directory . '/source-audio-all-packets-command.json', $packetArguments);
    $packets = $packetProbe['packets'] ?? [];
    if ($packets === [] || count($packets) > 512) throw new RuntimeException('The four-second audio source must have a bounded nonempty packet list.');
    $sourceAudioEnd = 0.0;
    $sourceAudioPacketCount = count($packets);
    $sourceAudioPacketsAfterStart = 0;
    foreach ($packets as $packet) {
        if (($packet['stream_index'] ?? null) !== $rawAudioStreams[$selectedAudioOrdinal]['index']
            || !is_numeric($packet['pts_time'] ?? null) || !is_numeric($packet['duration_time'] ?? null)) {
            throw new RuntimeException('Every short-source audio packet must expose its selected index and real timing.');
        }
        $start = (float) $packet['pts_time'];
        $duration = (float) $packet['duration_time'];
        if (!is_finite($start) || !is_finite($duration) || $start < 0 || $duration <= 0) {
            throw new RuntimeException('Short-source audio packet timing must be finite and nonnegative.');
        }
        $sourceAudioEnd = max($sourceAudioEnd, $start + $duration);
        if ($start + $duration > $sourceStartTime + 0.000001) $sourceAudioPacketsAfterStart++;
    }
    if (abs($sourceAudioEnd - 4.0) > 0.0001 || ($sourceStartTime > 0 && $sourceAudioPacketsAfterStart !== 0)) {
        throw new RuntimeException('The source soundtrack must end at four seconds with no packets remaining after the six-second restart.');
    }
}
$generator = new Generator();
$ffmpegPort = $generator->testDouble(FFmpegPortInterface::class, false);
$storagePort = $generator->testDouble(TranscodeStoragePortInterface::class, false);
assert($ffmpegPort instanceof FFmpegPortInterface);
assert($storagePort instanceof TranscodeStoragePortInterface);
$encoder = new SegmentEncoder($ffmpegPort, $storagePort, EncoderProfile::software('libx264'));
$origin = 'http://adaptive.baander.app:18778';
$state = ['origin' => $origin, 'videoId' => Uuid::generate(), 'videoPublicId' => new PublicId(),
    'audioProfile' => $audioProfile->name, 'sourceStartTime' => $sourceStartTime, 'jobs' => []];
$owner = Uuid::generate();
$commands = $readyTiers = $pairs = [];
foreach (['low' => QualityTier::p360(), 'high' => QualityTier::p720()] as $label => $tier) {
    $job = TranscodeJob::create($state['videoId'], $tier, $directory, $owner);
    $job->updateProbeData($probe->jsonSerialize());
    $output = $directory . '/' . $state['videoId'] . '/' . $tier->name . '/job-' . $job->getId() . '/generation-1';
    mkdir($output, 0750, true);
    AdaptiveBrowserFixture::writeJson($output . '/generation.json', ['schemaVersion' => 2, 'generation' => 1,
        'videoCodec' => 'h264', 'videoEncoder' => 'libx264', 'audioCodec' => $audioProfile->codec,
        'audioProfile' => $audioProfile->jsonSerialize(), 'duration' => $probe->duration,
        'startTime' => $sourceStartTime, 'nativeAvailabilityStartTime' => gmdate('Y-m-d\TH:i:s\Z')]);
    $arguments = $encoder->buildStreamArgs($source, $tier, $encoder->buildVideoFilters($probe, $tier), $output,
        startSegment: $sourceStartTime > 0 ? 1 : null, audioStreamIndex: $selectedAudioOrdinal, audioProfile: $audioProfile, audioFilters: $encoder->buildAudioFiltersForProfile($probe, $audioProfile), hasAudio: true,
        sourceFrameRate: $probe->framerate, sourceTimestampOrigin: $probe->sourceTimestampOrigin);
    if ($hasSubtitles) {
        $track = $probe->subtitleStreams[0] ?? throw new RuntimeException('The delayed movie must contain its embedded subtitle.');
        $subtitleDirectory = $output . '/subtitles/' . $track->trackKey();
        mkdir($subtitleDirectory, 0750, true);
        $extracted = $directory . '/subtitle-source-' . $tier->name . '.vtt';
        $subtitleArguments = FFmpegArguments::subtitleExtraction($source, $track->index, $extracted, $probe->sourceTimestampOrigin);
        $run($subtitleArguments);
        (new WebVttTimeline())->rebase($extracted, $subtitleDirectory . '/full.vtt', $sourceStartTime);
        AdaptiveBrowserFixture::writeJson($directory . '/subtitle-command-' . $tier->name . '.json', $subtitleArguments);
    }
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
        'expectedDuration' => $probe->duration - $sourceStartTime, 'frameRate' => $probe->framerate,
        'sourceTimestampOrigin' => $probe->sourceTimestampOrigin, 'rawSourceDuration' => $rawProbe['format']['duration'] ?? null,
        'expectedAudioDelay' => $delayedAudio ? 8 - $sourceStartTime : 0,
        'sourceAudioEnd' => $sourceAudioEnd, 'sourceAudioPacketCount' => $sourceAudioPacketCount,
        'sourceAudioPacketsAfterStart' => $sourceAudioPacketsAfterStart, 'expectedAudibleUntil' => $shortAudio ? max(0, 4 - $sourceStartTime) : null,
        'selectedAudioOrdinal' => $selectedAudioOrdinal, 'selectedAudioGlobalIndex' => $rawAudioStreams[$selectedAudioOrdinal]['index'],
        'selectedAudio' => $selectedAudio->jsonSerialize(), 'sourceAudioStreams' => $rawAudioStreams,
        'expectedAudioFrequency' => ($defaultAudio || $shortAudio) ? 880 : null, 'rejectedAudioFrequency' => $defaultAudio ? 220 : null,
        'sourceDuration' => $probe->duration, 'sourceVideoFirstPts' => $sourceVideoFirstPts, 'sourceAudioFirstPts' => $sourceAudioFirstPts,
        'hasSubtitles' => $hasSubtitles,
        'expectedCues' => $hasSubtitles ? ($sourceStartTime > 0
            ? [['start' => 6, 'end' => 8, 'text' => 'Later source cue']]
            : [['start' => 2, 'end' => 4, 'text' => 'Early source cue'], ['start' => 12, 'end' => 14, 'text' => 'Later source cue']]) : [],
        'sourceFrameRate' => $probe->framerate, 'sourceStartTime' => $sourceStartTime, 'scenario' => $scenario, 'ffmpegVersion' => $version,
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
            if (!isset($producers[$name]) && $startedAt !== null && microtime(true) >= $startedAt + ($scenario === 'adaptive' && $name === '720p' ? 6 : 0)) {
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
