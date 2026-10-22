<?php

declare(strict_types=1);

use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\DASH\DashManifestGenerator;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use App\Transcode\Infrastructure\Packaging\PublishedTrack;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$directory = getenv('BAANDER_PROGRESSIVE_DIRECTORY');
if ($directory === false || !is_dir($directory)) {
    throw new RuntimeException('A disposable media directory is required.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$videoBase = '/api/transcode/' . str_repeat('V', 21);
$jobBase = '/api/transcode/' . str_repeat('J', 21) . '/tracks/';
$routes = [$videoBase . '/master.m3u8' => '/master.m3u8', $videoBase . '/manifest.mpd' => '/presentation.mpd',
    $jobBase . 'video/media.m3u8' => '/video.m3u8', $jobBase . 'audio/media.m3u8' => '/audio.m3u8'];
$route = $routes[$path] ?? $path;
$auth = new HmacStreamAuthAdapter('disposable-baander-publication-fixture');
$query = $_GET;
$provided = $query['sig'] ?? '';
$expires = filter_var($query['exp'] ?? '', FILTER_VALIDATE_INT);
unset($query['sig'], $query['exp']);
$unsigned = $path . ($query === [] ? '' : '?' . http_build_query($query));
if (!is_string($provided) || $expires === false || !$auth->validateUrl($unsigned, $provided, $expires)) {
    file_put_contents($directory . '/requests.jsonl', json_encode(['path' => $path, 'status' => 403,
        'closed' => true, 'complete' => false, 'segments' => 0, 'time' => microtime(true)], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(403);
    return;
}
$playlistUrl = static fn (MediaTrack $kind): string => $auth->signUrl($jobBase . $kind->value . '/media.m3u8?generation=1')['url'];
$reader = new PublishedPresentationReader();
$presentation = $reader->read($directory);
if ($route === '/presentation.mpd' && $presentation !== null && !$presentation->complete) {
    usleep(1_000_000);
    $presentation = $reader->read($directory);
}
if ($presentation === null) {
    file_put_contents($directory . '/requests.jsonl', json_encode(['path' => $path, 'status' => 503,
        'closed' => true, 'complete' => false, 'segments' => 0, 'time' => microtime(true)], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(503);
    return;
}
$status = 200;
$closed = true;
$body = '';
$contentType = 'application/octet-stream';
$video = $presentation->track(MediaTrack::Video);
$audio = $presentation->track(MediaTrack::Audio);
$complete = $presentation->complete;
$segmentCount = count($presentation->tracks[MediaTrack::Video->value]->segments);
$url = static function (MediaTrack $kind, string $resource) use ($auth, $jobBase): string {
    $query = $resource === 'init.mp4' ? ['generation' => 1] : ['index' => (int) $resource, 'generation' => 1];
    return $auth->signUrl($jobBase . $kind->value . '/' . ($resource === 'init.mp4' ? 'init.mp4' : 'segment.m4s')
        . '?' . http_build_query($query))['url'];
};
$urls = static function (PublishedTrack $track) use ($url): array {
    $result = [];
    foreach ($track->segments as $segment) {
        $result[$segment->ordinal] = $url($track->kind, $segment->ordinal . '.m4s');
    }
    return $result;
};
$tier = QualityTier::fromString('720p');
if ($video === null || $audio === null) {
    $status = 503;
} elseif ($route === '/master.m3u8') {
    $contentType = 'application/vnd.apple.mpegurl';
    $body = (new ManifestGenerator())->generateMasterManifest(['720p' => $playlistUrl(MediaTrack::Video)],
        [['language' => 'und', 'name' => 'Stereo', 'uri' => $playlistUrl(MediaTrack::Audio), 'codec' => $audio->codec, 'isDefault' => true]],
        videoCodecRfc6381: ['720p' => $video->codec . ',' . $audio->codec], bandwidths: ['720p' => 400_000]);
} elseif ($route === '/video.m3u8' || $route === '/audio.m3u8') {
    $track = $route === '/video.m3u8' ? $video : $audio;
    $segments = [];
    foreach ($track->segments as $segment) {
        $segments[$segment->ordinal] = ['url' => $url($track->kind, $segment->ordinal . '.m4s'),
            'duration' => $segment->duration / $track->timescale];
    }
    $contentType = 'application/vnd.apple.mpegurl';
    $body = (new ManifestGenerator())->generateMediaManifest($url($track->kind, 'init.mp4'), $segments, $presentation->complete);
    file_put_contents($directory . '/served-' . $track->kind->value . '.m3u8', $body);
} elseif ($route === '/presentation.mpd') {
    $contentType = 'application/dash+xml';
    $body = (new DashManifestGenerator())->generate(['720p' => ['quality_tier' => $tier, 'track' => $video,
        'init_url' => $url(MediaTrack::Video, 'init.mp4'), 'segment_urls' => $urls($video), 'bandwidth' => 200_000]],
        ['track' => $audio, 'init_url' => $url(MediaTrack::Audio, 'init.mp4'), 'segment_urls' => $urls($audio),
            'language' => 'und', 'bandwidth' => 128_000, 'sample_rate' => 48000, 'channels' => 2], $presentation);
    file_put_contents($directory . '/served.mpd', $body);
} elseif (is_string($path) && preg_match('#^' . preg_quote($jobBase, '#') . '(video|audio)/(init\.mp4|segment\.m4s)$#D', $path, $match) === 1) {
    $kind = MediaTrack::from($match[1]);
    $track = $presentation->track($kind);
    $resource = $match[2] === 'init.mp4' ? 'init.mp4' : (string) ($_GET['index'] ?? '-1');
    if (($_GET['generation'] ?? '') !== '1') {
        $status = 403;
    } else {
        $file = $resource === 'init.mp4' ? $track?->initFile : ($track?->segments[(int) $resource]->file ?? null);
        if ($file === null || !is_file($directory . '/' . $file) || is_link($directory . '/' . $file)) {
            $closed = false;
            $status = 404;
        } else {
            $body = file_get_contents($directory . '/' . $file);
            if ($body === false) {
                throw new RuntimeException('A published media file disappeared.');
            }
            $contentType = $kind->value . '/mp4';
        }
    }
} else {
    $status = 404;
}
file_put_contents($directory . '/requests.jsonl', json_encode(['path' => $path, 'status' => $status,
    'closed' => $closed, 'complete' => $complete, 'consumer' => $_SERVER['HTTP_X_BAANDER_TEST_CONSUMER'] ?? '',
    'segments' => $segmentCount, 'index' => isset($_GET['index']) ? (int) $_GET['index'] : null, 'urlBytes' => strlen('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']), 'time' => microtime(true)], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
http_response_code($status);
header('Content-Type: ' . $contentType);
header('Content-Length: ' . strlen($body));
header('Cache-Control: no-store');
echo $body;
