<?php

declare(strict_types=1);

use App\Shared\Domain\Model\PublicId;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__ . '/AdaptiveBrowserFixture.php';

$directory = AdaptiveBrowserFixture::directory();
// Only the bootstrap writes this private fixture state; it is never an HTTP input.
$state = unserialize((string) file_get_contents($directory . '/state.serialized'));
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Cache-Control: no-store');
$assets = getenv('BAANDER_ADAPTIVE_ASSETS') ?: '/tmp/baander-adaptive-assets';
$files = ['/__adaptive/' => [$assets . '/index.html', 'text/html'],
    '/__adaptive/entry.js' => [$assets . '/entry.js', 'application/javascript']];
if (isset($files[$path])) {
    [$file, $type] = $files[$path];
    header('Content-Type: ' . $type);
    readfile($file);
    return;
}
if ($path === '/favicon.ico') {
    http_response_code(204);
    return;
}
if ($path === '/__adaptive/start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents($directory . '/start', 'start', LOCK_EX);
    http_response_code(202);
    header('Content-Type: application/json');
    echo '{"started":true}';
    return;
}
if ($path === '/__adaptive/status' || $path === '/__adaptive/ready') {
    header('Content-Type: application/json');
    echo json_encode($path === '/__adaptive/status' ? AdaptiveBrowserFixture::status($state)
        : AdaptiveBrowserFixture::json($directory . '/ready.json'), JSON_THROW_ON_ERROR);
    return;
}
if ($path === '/__adaptive/requests') {
    $file = $directory . '/requests.jsonl';
    if (is_file($file) && filesize($file) > 2_000_000) {
        throw new RuntimeException('The bounded adaptive request log overflowed.');
    }
    $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    header('Content-Type: application/json');
    echo json_encode(array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        $lines === false ? [] : $lines), JSON_THROW_ON_ERROR);
    return;
}

$request = Request::createFromGlobals();
[$streaming, $manifests, $signed] = AdaptiveBrowserFixture::services($state);
$jobId = $tier = $trackName = $index = null;
$segmentStart = $segmentEnd = null;
$closed = false;
$status = AdaptiveBrowserFixture::status($state);
try {
    if (preg_match('~^/api/transcode/([^/]+)/master\.m3u8$~D', $path, $matches) === 1) {
        $response = $manifests->masterManifest($matches[1], $request);
    } elseif (preg_match('~^/api/transcode/([^/]+)/tracks/(video|audio)/(media\.m3u8|init\.mp4|segment\.m4s)$~D', $path, $matches) === 1) {
        [, $jobId, $trackName, $resource] = $matches;
        foreach ($state['jobs'] as $name => $entry) {
            if ($entry['job']->getPublicId()->toString() === $jobId) {
                $tier = $name;
            }
        }
        $track = MediaTrack::from($trackName);
        if ($resource === 'media.m3u8') {
            $response = $manifests->mediaManifest($jobId, $track, $request);
        } else {
            $signed->validate($request);
            $generation = $request->query->getInt('generation');
            $index = $resource === 'segment.m4s' ? $request->query->getInt('index', -1) : null;
            $file = $index === null
                ? $streaming->getInitSegmentPath(PublicId::fromString($jobId), $track, $generation)
                : ($index < 0 ? null : $streaming->getSegmentPath(PublicId::fromString($jobId), $track, $index, $generation));
            // Production lookup resolves only closed files in the published native timeline.
            $closed = $file !== null && is_file($file) && !str_ends_with($file, '.tmp') && !str_ends_with($file, '.part');
            if ($closed && $index !== null && $tier !== null) {
                $publishedTrack = (new PublishedPresentationReader())->read($state['jobs'][$tier]['directory'])?->track($track);
                $segment = $publishedTrack?->segments[$index] ?? null;
                if ($segment !== null) {
                    $segmentStart = ($segment->start - $publishedTrack->presentationTimeOffset) / $publishedTrack->timescale;
                    $segmentEnd = ($segment->start + $segment->duration - $publishedTrack->presentationTimeOffset) / $publishedTrack->timescale;
                }
            }
            $bytes = $closed ? file_get_contents($file) : false;
            $response = new Response($bytes === false ? '' : $bytes, $bytes === false ? 503 : 200,
                ['Content-Type' => $track === MediaTrack::Video ? 'video/mp4' : 'audio/mp4', 'Retry-After' => '2']);
        }
    } elseif (preg_match('~^/api/transcode/([^/]+)/subtitles/(s[0-9]+)/(media\.m3u8|full\.vtt)$~D', $path, $matches) === 1) {
        [, $jobId, $language, $resource] = $matches;
        $trackName = 'subtitle';
        if ($resource === 'media.m3u8') {
            $response = $manifests->subtitleManifest($jobId, $language, $request);
        } else {
            $signed->validate($request);
            $file = $streaming->getSubtitleSegmentPath(PublicId::fromString($jobId), $language, 'full', $request->query->getInt('generation'));
            $closed = $file !== null && is_file($file);
            $response = new Response($closed ? file_get_contents($file) : '', $closed ? 200 : 404, ['Content-Type' => 'text/vtt']);
        }
    } else {
        $response = new Response('Not found', 404);
    }
} catch (HttpExceptionInterface $error) {
    $response = new Response($error->getMessage(), $error->getStatusCode());
} catch (InvalidArgumentException $error) {
    $response = new Response($error->getMessage(), 400);
}
ob_start();
$response->sendContent();
$body = (string) ob_get_clean();
$receipt = ['time' => microtime(true), 'path' => $_SERVER['REQUEST_URI'], 'jobId' => $jobId, 'tier' => $tier,
    'track' => $trackName, 'index' => $index, 'status' => $response->getStatusCode(), 'bytes' => strlen($body),
    'segmentStart' => $segmentStart, 'segmentEnd' => $segmentEnd,
    'closed' => $closed, 'complete' => $tier === null ? $status['complete'] : $status['tiers'][$tier]['complete'],
    'videoEnd' => $tier === null ? null : $status['tiers'][$tier]['videoEnd']];
$log = $directory . '/requests.jsonl';
if (is_file($log) && filesize($log) > 2_000_000) {
    throw new RuntimeException('The bounded adaptive request log overflowed.');
}
file_put_contents($log, json_encode($receipt, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
$response->sendHeaders();
echo $body;
