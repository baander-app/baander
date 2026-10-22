<?php

declare(strict_types=1);

use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$directory = getenv('BAANDER_PROGRESSIVE_DIRECTORY');
if ($directory === false || !is_dir($directory)) {
    throw new RuntimeException('A disposable publication directory is required.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$assets = '/tmp/baander-publication-assets';
if ($path === '/favicon.ico') {
    http_response_code(204);
    return;
}
$files = ['/__publication/' => [$assets . '/index.html', 'text/html'],
    '/__publication/dash.all.min.js' => [$assets . '/dash.all.min.js', 'application/javascript']];
if (isset($files[$path])) {
    [$file, $type] = $files[$path];
    header('Content-Type: ' . $type);
    header('Cache-Control: no-store');
    readfile($file);
    return;
}
if ($path === '/__publication/start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents($directory . '/start', 'start', LOCK_EX);
    http_response_code(202);
    header('Content-Type: application/json');
    echo '{"started":true}';
    return;
}
if ($path === '/__publication/status') {
    $presentation = (new PublishedPresentationReader())->read($directory);
    $producer = is_file($directory . '/producer.json')
        ? json_decode((string) file_get_contents($directory . '/producer.json'), true, 512, JSON_THROW_ON_ERROR) : null;
    $complete = false;
    $videoSegments = $audioSegments = 0;
    if ($presentation !== null) {
        $complete = $presentation->complete;
        $video = $presentation->track(MediaTrack::Video);
        $audio = $presentation->track(MediaTrack::Audio);
        $videoSegments = $video === null ? 0 : count($video->segments);
        $audioSegments = $audio === null ? 0 : count($audio->segments);
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ready' => $presentation !== null, 'complete' => $complete,
        'videoSegments' => $videoSegments, 'audioSegments' => $audioSegments, 'producer' => $producer], JSON_THROW_ON_ERROR);
    return;
}
if ($path === '/__publication/requests') {
    $file = $directory . '/requests.jsonl';
    if (is_file($file) && filesize($file) > 512_000) {
        throw new RuntimeException('The bounded publication request log overflowed.');
    }
    $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        $lines === false ? [] : $lines), JSON_THROW_ON_ERROR);
    return;
}

// Signed media uses the same production reader, generators and HMAC boundary
// as the independent native-reader publication test.
require __DIR__ . '/ProgressiveMediaRouter.php';
