<?php

declare(strict_types=1);

use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\TwoCommand\TrackDelivery;
use App\Tests\Fixtures\WebRuntime\HttpExchange;
use App\Tests\Fixtures\WebRuntime\StreamClient;
use Symfony\Component\Process\Process;

$project = dirname(__DIR__, 3);
require $project . '/vendor/autoload.php';
// Production Composer intentionally excludes the development namespace.
spl_autoload_register(static function (string $class) use ($project): void {
    $prefix = 'App\\Tests\\';
    if (str_starts_with($class, $prefix)) {
        $path = $project . '/tests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($project . '/.env');
const BASE = 'http://127.0.0.1:9501';
const ROOT = '/var/www/html/storage/two-command';
const STATE = ROOT . '/state.json';
const EMAIL = 'two-command-admin@baander.app';
const VIDEO = '/media/source/video.mkv';
const AUDIO = '/media/source/track.flac';

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
/** @return array<string,mixed> */
function state(): array
{
    return json_decode((string) file_get_contents(STATE), true, flags: JSON_THROW_ON_ERROR);
}
/** @param array<string,mixed> $value */
function save(array $value): void
{
    file_put_contents(STATE, json_encode($value, JSON_THROW_ON_ERROR));
    chmod(STATE, 0600);
}
/** @param list<string> $arguments */
function command(array $arguments, float $timeout = 90): void
{
    $process = new Process($arguments);
    $process->setTimeout($timeout);
    $process->mustRun();
}
/** @return array<string,mixed> */
function jsonBody(HttpExchange $response): array
{
    return json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
}
/** @param array<string, string|int|float|bool>|null $payload */
function twoCommandExchange(StreamClient $client, string $path, string $method = 'GET', ?array $payload = null): HttpExchange
{
    $endpoint = BASE . $path;
    $proofEndpoint = explode('?', $endpoint, 2)[0];
    return new HttpExchange($endpoint, [...$client->proofHeaders($method, $proofEndpoint), 'Accept: application/json', 'Content-Type: application/json'], $method,
        $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));
}
function await(callable $probe, string $description, float $seconds = 30): mixed
{
    $deadline = microtime(true) + $seconds;
    do {
        clearstatcache();
        $value = $probe();
        if ($value !== false && $value !== null) {
            return $value;
        }
        usleep(50_000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Timed out: ' . $description);
}
/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function row(Doctrine\DBAL\Connection $db, array $state): array
{
    $row = $db->fetchAssociative('SELECT r.*, clock_timestamp() AS sampled_at, j.status AS job_status, s.public_id AS session_public_id FROM transcode_runtime_state r JOIN transcode_sessions s ON s.id=r.session_id JOIN transcode_jobs j ON j.id=r.job_id WHERE j.public_id = ?', [$state['job']]);
    ensure($row !== false, 'Durable runtime disappeared.');
    $row['desired'] = json_decode($row['desired'], true, flags: JSON_THROW_ON_ERROR);
    return $row;
}
/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function acknowledged(Doctrine\DBAL\Connection $db, array $state, string $status, ?float $position = null): array
{
    $last = [];
    try {
        return await(static function () use ($db, $state, $status, $position, &$last): array|false {
            $last = row($db, $state);
            ensure(!in_array($last['outcome'], ['failed', 'refused', 'cancelled'], true),
                'Worker cannot acknowledge playable controls after ' . $last['outcome'] . ': ' . ($last['error'] ?? '(no diagnostic)'));
            return $last['desired']['status'] === $status && (int) $last['observed_revision'] >= (int) $last['desired_revision']
                && in_array($last['outcome'], ['ready', 'complete'], true)
                && ($position === null || abs((float) $last['desired']['position'] - $position) < .01) ? $last : false;
        }, 'worker acknowledges ' . $status);
    } catch (RuntimeException $error) {
        $diagnostic = array_intersect_key($last, array_flip([
            'sampled_at', 'generation', 'desired_revision', 'observed_revision', 'outcome',
            'worker_boot_id', 'worker_lease_epoch', 'lease_expires_at', 'job_status', 'error',
        ]));
        $diagnostic['desiredStatus'] = $last['desired']['status'] ?? null;
        $diagnostic['desiredPosition'] = $last['desired']['position'] ?? null;
        $diagnostic['hasClaim'] = ($last['claim_token'] ?? null) !== null;
        $diagnostic['hasOutput'] = ($last['output_directory'] ?? null) !== null;
        fwrite(STDERR, json_encode(['acknowledgement' => $diagnostic], JSON_THROW_ON_ERROR) . "\n");
        throw $error;
    }
}
/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function playback(StreamClient $client, array $state): array
{
    $response = twoCommandExchange($client, '/api/transcode/sessions/' . $state['session'])->complete();
    ensure($response->status() === 200, 'Session read failed: ' . $response->body);
    $playback = jsonBody($response)['data']['playback'] ?? null;
    ensure(is_array($playback), 'Session omitted current playback.');
    return $playback;
}
function url(string $path): string
{
    return str_starts_with($path, '/') ? BASE . $path : $path;
}
/** @return array{decodedFrames: int, decodedPcmBytes: int} */
function decodeMedia(string $input, string $name, ?float $duration = null): array
{
    $video = ROOT . '/' . $name . '.gray';
    $audio = ROOT . '/' . $name . '.pcm';
    $limit = $duration === null ? [] : ['-t', (string) $duration];
    command(['ffmpeg', '-nostdin', '-v', 'error', '-xerror', '-y', '-threads', '1', '-filter_threads', '1', '-i', $input,
        ...$limit, '-map', '0:v:0', '-vf', 'scale=16:16', '-c:v', 'rawvideo', '-threads:v', '1', '-pix_fmt', 'gray', '-f', 'rawvideo', $video,
        ...$limit, '-map', '0:a:0', '-c:a', 'pcm_s16le', '-ac', '2', '-ar', '48000', '-f', 's16le', $audio], 45);
    clearstatcache(true, $video);
    clearstatcache(true, $audio);
    $videoBytes = is_file($video) ? (int) filesize($video) : 0;
    $audioBytes = is_file($audio) ? (int) filesize($audio) : 0;
    ensure($videoBytes > 0 && $videoBytes % 256 === 0, 'Media decode produced no complete video frames.');
    ensure($audioBytes > 0 && $audioBytes % 4 === 0, 'Media decode produced no complete stereo PCM samples.');
    return ['decodedFrames' => intdiv($videoBytes, 256), 'decodedPcmBytes' => $audioBytes];
}
/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function decodeVideo(StreamClient $client, array $state, float $readinessSeconds = 45): array
{
    $playback = await(static function () use ($client, $state): array|false {
        $value = playback($client, $state);
        return ($value['ready'] ?? false) ? $value : false;
    }, 'worker publishes current generation', $readinessSeconds);
    $master = (new HttpExchange(url($playback['manifestUrl']), []))->complete();
    ensure($master->status() === 200, 'Master playlist is unavailable: ' . $master->body);
    preg_match('~^([^#\r\n][^\r\n]*)~m', $master->body, $mediaMatch);
    ensure(isset($mediaMatch[1]), 'Master playlist has no rendition.');
    preg_match_all('/^#EXT-X-MEDIA:TYPE=AUDIO,[^\r\n]+/m', $master->body, $audioLines);
    ensure(count($audioLines[0]) === 1, 'Master must expose one canonical audio rendition.');
    preg_match('/URI="([^"]+)"/', $audioLines[0][0], $audioUri);
    ensure(isset($audioUri[1]), 'Canonical audio rendition omitted its playlist.');
    $resources = [];
    $playlists = [];
    $playlistUrls = ['video' => url($mediaMatch[1]), 'audio' => url($audioUri[1])];
    $playlistRequests = array_map(static fn(string $playlistUrl): HttpExchange => new HttpExchange($playlistUrl, []), $playlistUrls);
    foreach (HttpExchange::completeAll($playlistRequests) as $kind => $media) {
        ensure($media->status() === 200, $kind . ' media playlist is unavailable.');
        preg_match('/#EXT-X-MAP:URI="([^"]+)"/', $media->body, $initMatch);
        preg_match('~^([^#\r\n][^\r\n]*)~m', $media->body, $segmentMatch);
        preg_match('/#EXTINF:([0-9.]+)/', $media->body, $durationMatch);
        ensure(isset($initMatch[1], $segmentMatch[1], $durationMatch[1]), 'Playlist omitted init, fragment or duration.');
        $resources[$kind] = ['initUrl' => url($initMatch[1]), 'segmentUrl' => url($segmentMatch[1])];
        $playlists[$kind] = ['mediaUrl' => $playlistUrls[$kind], 'firstDuration' => (float) $durationMatch[1]];
    }
    $delivery = TrackDelivery::inspect($resources, ROOT . '/hls-' . $playback['generation']);
    $tracks = $delivery['tracks'];
    foreach ($tracks as $kind => &$track) {
        $track += $playlists[$kind];
    }
    unset($track);
    $decoded = decodeMedia(url($playback['manifestUrl']), 'decoded-hls-generation-' . $playback['generation'], 1.0);
    $subtitles = verifySubtitles($master->body, $playback);
    return $playback + $decoded + ['deliveryReadyAt' => $delivery['availableAt'], 'tracks' => $tracks, 'decodedBytes' => array_sum(array_column($tracks, 'bytes')), 'subtitles' => $subtitles];
}

/** @return array<string,mixed> */
function inspectTrack(string $kind, string $initUrl, string $segmentUrl, string $name): array
{
    return TrackDelivery::inspect([$kind => ['initUrl' => $initUrl, 'segmentUrl' => $segmentUrl]], ROOT . '/' . $name)['tracks'][$kind];
}

/**
 * @param array<string,mixed> $playback
 * @return array<string,array<string,mixed>>
 */
function verifySubtitles(string $master, array $playback): array
{
    preg_match_all('/^#EXT-X-MEDIA:TYPE=SUBTITLES,[^\r\n]+/m', $master, $matches);
    ensure(count($matches[0]) === 3, 'Ready master must expose all three independent subtitle tracks.');
    $evidence = [];
    foreach ($matches[0] as $line) {
        preg_match('/URI="([^"]+)"/', $line, $uri);
        ensure(isset($uri[1]), 'Subtitle rendition omitted URI.');
        preg_match('~/subtitles/(s[0-9]+)/~', $uri[1], $track);
        ensure(isset($track[1]) && !isset($evidence[$track[1]]), 'Subtitle track identity is absent or duplicated.');
        $key = $track[1];
        $playlist = (new HttpExchange(url($uri[1]), []))->complete();
        ensure($playlist->status() === 200, 'Ready subtitle playlist is unavailable.');
        preg_match('/#EXTINF:([0-9.]+)/', $playlist->body, $duration);
        $remaining = (float) $playback['duration'] - (float) $playback['startTime'];
        ensure(isset($duration[1]) && abs((float) $duration[1] - $remaining) < .05, 'Full subtitle resource advertises the wrong timeline duration.');
        preg_match('~^([^#\r\n][^\r\n]*)~m', $playlist->body, $resource);
        ensure(isset($resource[1]), 'Subtitle playlist omitted its resource.');
        $vtt = (new HttpExchange(url($resource[1]), []))->complete();
        ensure($vtt->status() === 200 && str_starts_with($vtt->body, 'WEBVTT'), 'Published subtitle is not a complete WebVTT resource.');
        $expected = ['s2' => 'Dialogue', 's3' => 'Forced', 's4' => 'Unlabelled'][$key] ?? null;
        ensure($expected !== null && str_contains($vtt->body, $expected . ' late'), 'Duplicate or unlabelled subtitle stream was lost or misselected.');
        preg_match('/((?:\d{2}:)?\d{2}:\d{2}\.\d{3}) --> ((?:\d{2}:)?\d{2}:\d{2}\.\d{3})\R' . preg_quote($expected, '/') . ' late/', $vtt->body, $cue);
        ensure(isset($cue[1], $cue[2]), 'Expected late cue was not parsed.');
        $seconds = static function (string $timestamp): float {
            [$hours, $minutes, $seconds] = array_pad(explode(':', $timestamp), -3, '0');
            return (int) $hours * 3600 + (int) $minutes * 60 + (float) $seconds;
        };
        ensure(abs($seconds($cue[1]) - max(0, 15 - (float) $playback['startTime'])) < .05
            && abs($seconds($cue[2]) - (40 - (float) $playback['startTime'])) < .05,
            'Subtitle cue timestamps do not follow the generation-local video timeline.');
        ensure(str_contains($line, 'DEFAULT=' . ($key === 's2' ? 'YES' : 'NO')), 'Subtitle default disposition was lost.');
        ensure(str_contains($line, 'FORCED=' . ($key === 's3' ? 'YES' : 'NO')), 'Subtitle forced disposition was lost.');
        $tampered = (new HttpExchange(url($resource[1]) . '&generation=999999', []))->complete(5);
        ensure(in_array($tampered->status(), [400, 403, 404], true), 'Altered subtitle signature was accepted.');
        $evidence[$key] = ['url' => url($resource[1]), 'bytes' => strlen($vtt->body), 'duration' => (float) $duration[1],
            'lateCueStart' => $seconds($cue[1]), 'lateCueEnd' => $seconds($cue[2]), 'tamperedStatus' => $tampered->status()];
    }
    return $evidence;
}

/**
 * @param array<string,mixed> $state
 * @return array<string,mixed>
 */
function verifyDash(StreamClient $client, array $state): array
{
    global $db;
    $signed = twoCommandExchange($client, '/api/stream/sign', 'POST', [
        'path' => '/api/transcode/' . $state['video'] . '/manifest.mpd', 'refresh' => true,
    ])->complete();
    ensure($signed->status() === 200, 'DASH signing failed: ' . $signed->body);
    $snapshot = jsonBody($signed);
    ensure(($snapshot['ready'] ?? false) && is_string($snapshot['url'] ?? null), 'DASH snapshot is not ready.');
    $manifest = (new HttpExchange(url($snapshot['url']), []))->complete();
    ensure($manifest->status() === 200, 'Signed DASH manifest is unavailable.');
    $xml = new DOMDocument();
    ensure($xml->loadXML($manifest->body, LIBXML_NONET), 'DASH manifest is invalid XML.');
    ensure($xml->documentElement?->getAttribute('profiles') === 'urn:mpeg:dash:profile:full:2011', 'DASH profile does not describe SegmentList output.');
    $xpath = new DOMXPath($xml); $xpath->registerNamespace('mpd', 'urn:mpeg:dash:schema:mpd:2011');
    $runtime = row($db, $state);
    ensure((int) $runtime['generation'] === (int) $snapshot['generation'], 'DASH output changed generation during inspection.');
    $presentation = (new App\Transcode\Infrastructure\Packaging\PublishedPresentationReader())->read($runtime['output_directory']);
    ensure($presentation !== null, 'DASH native presentation is unavailable.');
    $resources = [];
    foreach (['video', 'audio'] as $kind) {
        $representations = $xpath->query('//mpd:AdaptationSet[@contentType="' . $kind . '"]/mpd:Representation');
        ensure($representations !== false && $representations->length > 0, 'DASH omitted ' . $kind . ' representations.');
        ensure($kind !== 'audio' || $representations->length === 1, 'DASH must expose one canonical audio representation.');
        $representation = $representations->item(0);
        if (!$representation instanceof DOMElement || $representation->getAttribute('codecs') === '') {
            throw new RuntimeException('DASH omitted native codec declarations.');
        }
        $init = $xpath->evaluate('string(.//mpd:Initialization/@sourceURL)', $representation);
        $segment = $xpath->evaluate('string(.//mpd:SegmentURL/@media)', $representation);
        $points = $xpath->query('.//mpd:SegmentTimeline/mpd:S', $representation);
        $lists = $xpath->query('.//mpd:SegmentList', $representation);
        ensure($points !== false && $lists !== false, 'DASH timeline query failed.');
        $timing = $points->item(0);
        $list = $lists->item(0);
        if (!is_string($init) || $init === '' || !is_string($segment) || $segment === '' || !$timing instanceof DOMElement || !$list instanceof DOMElement) {
            throw new RuntimeException('DASH omitted explicit addressing or track timing.');
        }
        $resources[$kind] = inspectTrack($kind, url($init), url($segment), 'dash-' . $snapshot['generation']);
        $resources[$kind]['timescale'] = (int) $list->getAttribute('timescale');
        $resources[$kind]['startTicks'] = (int) $timing->getAttribute('t');
        $resources[$kind]['durationTicks'] = (int) $timing->getAttribute('d');
        ensure($resources[$kind]['timescale'] > 0 && $resources[$kind]['durationTicks'] > 0, 'DASH track timing must be positive.');
        $native = $presentation->track(App\Transcode\Application\DTO\MediaTrack::from($kind));
        ensure($native !== null && $resources[$kind]['timescale'] === $native->timescale
            && $resources[$kind]['startTicks'] === $native->segments[0]->start
            && $resources[$kind]['durationTicks'] === $native->segments[0]->duration
            && $representation->getAttribute('codecs') === $native->codec,
            'Signed DASH timing or codec differs from the native publication.');
    }
    return ['generation' => $snapshot['generation'], 'profile' => $xml->documentElement->getAttribute('profiles'),
        'qualification' => 'decoded-audio-and-video', 'demuxQualified' => true, 'resources' => $resources]
        + decodeMedia(url($snapshot['url']), 'decoded-dash-generation-' . $snapshot['generation'], 1.0);
}
/**
 * @return array{urls: list<string>, counts: array<string,int>, completedAt: float}
 */
function traverseSignedDelivery(string $manifestUrl, int $expires): array
{
    $pending = [url($manifestUrl)];
    $visited = [];
    $counts = [];
    while ($pending !== []) {
        $resource = array_shift($pending);
        if (isset($visited[$resource])) { continue; }
        ensure(count($visited) < 512, 'Signed delivery traversal exceeded its resource bound.');
        parse_str((string) parse_url($resource, PHP_URL_QUERY), $query);
        ensure(($query['exp'] ?? null) === (string) $expires, 'A signed descendant changed its parent expiry: ' . $resource);
        ensure(time() <= $expires, 'Signed delivery traversal missed its expiry deadline.');
        $response = (new HttpExchange($resource, []))->complete(5);
        ensure($response->status() === 200, 'Signed resource unavailable before expiry: ' . $resource . ' HTTP ' . $response->status());
        $visited[$resource] = true;
        $path = (string) parse_url($resource, PHP_URL_PATH);
        $kind = str_contains($path, '/subtitles/') ? 'subtitle' : (str_contains($path, '/tracks/audio/') ? 'audio' : 'video');
        $type = match (true) {
            str_ends_with($path, '/master.m3u8') => 'master',
            str_ends_with($path, '/manifest.mpd') => 'dash',
            str_ends_with($path, '.m3u8') => $kind . 'Playlist',
            str_ends_with($path, '/init.mp4') => $kind . 'Init',
            str_ends_with($path, '/segment.m4s') => $kind . 'Media',
            str_ends_with($path, '.vtt') => 'subtitleVtt',
            default => throw new RuntimeException('Unexpected signed resource: ' . $path),
        };
        $counts[$type] = ($counts[$type] ?? 0) + 1;
        $children = [];
        if (str_ends_with($path, '.m3u8')) {
            preg_match_all('/URI="([^"]+)"|^([^#\r\n][^\r\n]*)/m', $response->body, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($matches as $match) { $children[] = $match[1] ?? $match[2]; }
            ensure($children !== [], 'HLS playlist omitted signed children.');
            // A second read must retain the same deadline.
            $repeated = (new HttpExchange($resource, []))->complete(5);
            ensure($repeated->status() === 200, 'Repeated signed playlist read failed before expiry.');
            preg_match_all('/URI="([^"]+)"|^([^#\r\n][^\r\n]*)/m', $repeated->body, $repeatedMatches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($repeatedMatches as $match) { $children[] = $match[1] ?? $match[2]; }
        } elseif (str_ends_with($path, '.mpd')) {
            $xml = new DOMDocument();
            ensure($xml->loadXML($response->body, LIBXML_NONET), 'Expiry probe DASH manifest is invalid XML.');
            $xpath = new DOMXPath($xml);
            $xpath->registerNamespace('mpd', 'urn:mpeg:dash:schema:mpd:2011');
            $nodes = $xpath->query('//mpd:Initialization/@sourceURL | //mpd:SegmentURL/@media');
            ensure($nodes !== false && $nodes->length > 0, 'DASH omitted signed resources.');
            foreach ($nodes as $node) { $children[] = $node->nodeValue; }
        }
        foreach ($children as $child) { $pending[] = url($child); }
    }
    ensure(time() <= $expires, 'Signed delivery traversal completed after its expiry deadline.');
    foreach (['videoInit', 'videoMedia', 'audioInit', 'audioMedia'] as $type) {
        ensure(($counts[$type] ?? 0) > 0, 'Expiry probe omitted ' . $type . '.');
    }
    if (isset($counts['master'])) {
        foreach (['videoPlaylist', 'audioPlaylist', 'subtitlePlaylist', 'subtitleVtt'] as $type) {
            ensure(($counts[$type] ?? 0) >= (str_starts_with($type, 'subtitle') ? 3 : 1), 'Expiry probe omitted ' . $type . '.');
        }
    }
    return ['urls' => array_keys($visited), 'counts' => $counts, 'completedAt' => microtime(true)];
}

/**
 * @param array<string,mixed> $state
 * @return array<string,mixed>
 */
function verifySignedDeliveryExpiry(StreamClient $client, array $state): array
{
    $window = 30;
    $evidence = [];
    foreach (['hls' => 'master.m3u8', 'dash' => 'manifest.mpd'] as $protocol => $file) {
        $signed = twoCommandExchange($client, '/api/stream/sign', 'POST', [
            'path' => '/api/transcode/' . $state['video'] . '/' . $file,
            'refresh' => true, 'expiresInSeconds' => $window,
        ])->complete(5);
        ensure($signed->status() === 200, 'Short-lived authenticated signing failed.');
        $snapshot = jsonBody($signed);
        ensure(($snapshot['ready'] ?? false) && is_string($snapshot['url'] ?? null), 'Expiry probe requires ready publication.');
        parse_str((string) parse_url($snapshot['url'], PHP_URL_QUERY), $query);
        $expires = filter_var($query['exp'] ?? '', FILTER_VALIDATE_INT);
        ensure(is_int($expires) && $expires >= time() && $expires <= time() + $window, 'Short-lived signing returned an invalid deadline.');
        $traversal = traverseSignedDelivery($snapshot['url'], $expires);
        $tamperedCount = 0;
        foreach ($traversal['urls'] as $resource) {
            $tampered = preg_replace('/([?&]exp=)[0-9]+(?=&|$)/', '${1}' . ($expires + 3600), $resource);
            ensure(is_string($tampered) && $tampered !== $resource, 'Cannot alter signed resource expiry.');
            ensure((new HttpExchange($tampered, []))->complete(5)->status() === 403, 'Altering signed expiry was accepted.');
            ++$tamperedCount;
        }
        ensure(time() <= $expires, 'Expiry tampering checks missed the short-lived deadline.');
        $evidence[$protocol] = ['expires' => $expires, 'windowSeconds' => $window,
            'resourceCount' => count($traversal['urls']), 'tamperedRefusals' => $tamperedCount] + $traversal;
    }
    $refusalAt = max(array_column($evidence, 'expires')) + 1;
    ensure($refusalAt - time() <= $window + 1, 'Expiry refusal wait exceeds its bound.');
    await(static fn (): bool => time() >= $refusalAt, 'first integer second after signed expiry', $window + 2);
    foreach ($evidence as &$proof) {
        $proof['refusalStartedAt'] = microtime(true);
        $refused = 0;
        foreach ($proof['urls'] as $resource) {
            ensure((new HttpExchange($resource, []))->complete(5)->status() === 403, 'Expired signed resource remained accessible: ' . $resource);
            ++$refused;
        }
        $proof['expiredRefusals'] = $refused;
    }
    unset($proof);
    foreach (['hls' => 'master.m3u8', 'dash' => 'manifest.mpd'] as $protocol => $file) {
        $fresh = twoCommandExchange($client, '/api/stream/sign', 'POST', [
            'path' => '/api/transcode/' . $state['video'] . '/' . $file,
            'refresh' => true, 'expiresInSeconds' => $window,
        ])->complete(5);
        ensure($fresh->status() === 200, 'Authenticated signing refresh failed after expiry.');
        $snapshot = jsonBody($fresh);
        ensure(($snapshot['ready'] ?? false) && is_string($snapshot['url'] ?? null), 'Authenticated refresh lost ready publication.');
        parse_str((string) parse_url($snapshot['url'], PHP_URL_QUERY), $query);
        $expires = filter_var($query['exp'] ?? '', FILTER_VALIDATE_INT);
        ensure(is_int($expires) && $expires > $evidence[$protocol]['expires'], 'Authenticated refresh did not advance expiry.');
        $resumed = traverseSignedDelivery($snapshot['url'], $expires);
        $evidence[$protocol]['refresh'] = ['expires' => $expires, 'resourceCount' => count($resumed['urls'])] + $resumed;
    }
    return $evidence;
}

/**
 * @param array<string,mixed> $decoded
 * @return array<string,mixed>
 */
function videoEvidence(array $decoded): array
{
    return array_intersect_key($decoded, array_flip([
        'jobId', 'sessionId', 'ready', 'generation', 'revision', 'status',
        'position', 'startTime', 'duration', 'renditionSet', 'decodedBytes', 'decodedFrames', 'decodedPcmBytes', 'tracks', 'subtitles',
    ]));
}
/**
 * @param array<string,mixed> $state
 * @param array<string,mixed> $decoded
 * @return array<string,mixed>
 */
function retainVideoOutput(Doctrine\DBAL\Connection $db, array $state, array $decoded, string $name): array
{
    $runtime = row($db, $state);
    ensure((int) $runtime['generation'] === (int) $decoded['generation'], 'Output changed generation during retention.');
    $files = [];
    $paths = [];
    $urls = [];
    $presentation = (new App\Transcode\Infrastructure\Packaging\PublishedPresentationReader())->read($runtime['output_directory']);
    ensure($presentation !== null, 'Cannot read retained native presentation.');
    foreach (App\Transcode\Application\DTO\MediaTrack::cases() as $kind) {
        $track = $presentation->track($kind);
        ensure($track !== null, 'Retained presentation omitted ' . $kind->value . '.');
        $delivery = $decoded['tracks'][$kind->value];
        parse_str((string) parse_url($delivery['segmentUrl'], PHP_URL_QUERY), $query);
        ensure(isset($query['index']) && ctype_digit((string) $query['index']), 'Retained fragment omitted its ordinal.');
        $segment = $track->segments[(int) $query['index']] ?? null;
        ensure($segment !== null, 'Retained fragment is absent from the published index.');
        ensure(abs($delivery['firstDuration'] - $segment->duration / $track->timescale) < .00001,
            'Signed HLS duration differs from the native publication.');
        foreach (['init' => $track->initFile, 'media' => $segment->file] as $part => $file) {
            $key = $kind->value . '-' . $part;
            $paths[$key] = $runtime['output_directory'] . '/' . $file;
            $urls[$key] = $delivery[$part === 'init' ? 'initUrl' : 'segmentUrl'];
        }
    }
    foreach ($decoded['subtitles'] as $key => $subtitle) {
        $paths[$key] = $runtime['output_directory'] . '/subtitles/' . $key . '/full.vtt';
        $urls[$key] = $subtitle['url'];
    }
    foreach ($paths as $kind => $path) {
        $copy = ROOT . '/retained-' . $name . '-' . $kind . '.bin';
        ensure(is_file($path) && filesize($path) > 0 && copy($path, $copy) && chmod($copy, 0600), 'Cannot retain closed native output.');
        $files[$kind] = ['path' => $path, 'copy' => $copy, 'url' => $urls[$kind]];
    }
    return ['generation' => (int) $decoded['generation'], 'files' => $files];
}
/**
 * @param array<string,mixed> $retained
 * @return array<string,int>
 */
function rejectRetiredVideoOutput(array $retained): array
{
    $statuses = [];
    foreach ($retained['files'] as $kind => $file) {
        $directory = dirname($file['path']);
        ensure(is_dir($directory) || mkdir($directory, 0750, true), 'Cannot restore retired output directory.');
        ensure(copy($file['copy'], $file['path']) && filesize($file['path']) > 0, 'Retired output bytes are absent.');
        // Each request starts after retirement; already-open responses have their own boundary.
        $response = (new HttpExchange($file['url'], []))->complete(5);
        ensure(in_array($response->status(), [403, 404, 410], true), 'Retired generation ' . $kind . ' remained deliverable: HTTP ' . $response->status());
        $statuses[$kind] = $response->status();
    }
    return $statuses;
}
$mode = $argv[1] ?? '';
$started = microtime(true);
set_exception_handler(static function (Throwable $error) use ($mode): never {
    fwrite(STDERR, 'two-command ' . $mode . ': ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
});
$kernel = new App\Kernel('prod', false);
$kernel->boot();
$manager = $kernel->getContainer()->get('doctrine')->getManager();
$db = $manager->getConnection();
$client = static fn (): StreamClient => new StreamClient($manager, EMAIL,
    (string) file_get_contents($project . '/config/secrets/oauth/private.key'), BASE . '/api/stream/track');
$result = [];
if ($mode === 'prepare') {
    ensure(is_dir(ROOT) || mkdir(ROOT, 0750, true), 'Cannot create scratch state.');
    $movieEndpoint = getenv('BAANDER_TEST_MOVIE_ENDPOINT') === '1';
    $catalogDuration = 180.0;
    if ($movieEndpoint) {
        $sourceCommand = ['ffmpeg', '-nostdin', '-v', 'error', '-y', '-filter_threads', '1',
            '-f', 'lavfi', '-i', 'testsrc2=size=1280x720:rate=25:duration=42',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=48',
            '-map', '0:v', '-map', '1:a', '-c:v', 'libx264', '-preset', 'ultrafast', '-threads', '2',
            '-pix_fmt', 'yuv420p', '-bf', '0', '-c:a', 'pcm_s16le', VIDEO];
        command($sourceCommand, 90);
        $probeCommand = ['ffprobe', '-v', 'error', '-show_format', '-show_streams', '-show_packets',
            '-show_entries', 'packet=stream_index,pts_time,duration_time', '-of', 'json', VIDEO];
        $probeProcess = new Process($probeCommand, timeout: 30);
        $probeProcess->mustRun();
        file_put_contents(ROOT . '/endpoint-source-probe.json', $probeProcess->getOutput());
        $sourceProbe = json_decode($probeProcess->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $catalogDuration = (float) $sourceProbe['format']['duration'];
        $sourceEnds = [];
        $sourcePacketCounts = [];
        foreach ($sourceProbe['packets'] as $packet) {
            $index = (int) $packet['stream_index'];
            $end = (float) $packet['pts_time'] + (float) $packet['duration_time'];
            $sourceEnds[$index] = max($sourceEnds[$index] ?? 0.0, $end);
            $sourcePacketCounts[$index] = ($sourcePacketCounts[$index] ?? 0) + 1;
        }
        ensure(array_column($sourceProbe['streams'], 'codec_type') === ['video', 'audio'], 'Endpoint source must contain only movie video and audio.');
        ensure(abs($catalogDuration - 48.0) < 0.01, 'Endpoint source container must retain the longer soundtrack.');
        ensure(abs($sourceEnds[0] - 42.0) < 0.01 && abs($sourceEnds[1] - 48.0) < 0.01, 'Independently probed packet ends must distinguish movie video from audio.');
        ensure($sourcePacketCounts[0] === 1050, 'Endpoint source must retain every twenty-five-fps video frame.');
        file_put_contents(ROOT . '/endpoint-source.json', json_encode(['sourceCommand' => $sourceCommand,
            'probeCommand' => $probeCommand, 'packetEnds' => $sourceEnds, 'packetCounts' => $sourcePacketCounts,
            'catalogDuration' => $catalogDuration], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    } else {
        foreach (['Dialogue', 'Forced', 'Unlabelled'] as $title) {
            file_put_contents(ROOT . '/' . $title . '.srt', "1\n00:00:00,000 --> 00:00:15,000\n$title early\n\n2\n00:00:15,000 --> 00:00:40,000\n$title late\n\n3\n00:01:30,000 --> 00:02:30,000\n$title sought\n");
        }
        command(['ffmpeg', '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=1280x720:rate=30:duration=180',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=180',
            '-i', ROOT . '/Dialogue.srt', '-i', ROOT . '/Forced.srt', '-i', ROOT . '/Unlabelled.srt',
            '-map', '0:v', '-map', '1:a', '-map', '2:s', '-map', '3:s', '-map', '4:s',
            '-metadata:s:s:0', 'language=eng', '-metadata:s:s:0', 'title=Dialogue', '-disposition:s:0', 'default',
            '-metadata:s:s:1', 'language=eng', '-metadata:s:s:1', 'title=Forced', '-disposition:s:1', 'forced',
            '-metadata:s:s:2', 'language=', '-metadata:s:s:2', 'title=Unlabelled', '-disposition:s:2', '0',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-threads', '2', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-c:s', 'srt', '-t', '180', VIDEO], 150);
    }
    command(['ffmpeg', '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1800', '-ac', '2', '-ar', '44100', '-threads', '1', '-c:a', 'flac', AUDIO], 60);
    $library = new LibraryEntity('Two command music', 'two-command-music', '/media/source', 'music', 'local');
    $movies = new LibraryEntity('Two command movies', 'two-command-movies', '/media/source', 'movie', 'local');
    $album = new AlbumEntity(new PublicId(), $library, 'Two command album', 'two-command-album');
    $song = new SongEntity(new PublicId(), $album, 'Worker audio', AUDIO, (int) filesize(AUDIO), 'audio/flac');
    $video = new VideoEntity(new PublicId(), VIDEO, hash_file('sha256', VIDEO));
    $video->setDuration((int) round($catalogDuration)); $video->setWidth(1280); $video->setHeight(720); $video->setFramerate($movieEndpoint ? 25 : 30);
    $movie = new MovieEntity(new PublicId(), $movies, 'Two command playback');
    $movieVideo = new MovieVideoEntity($movie, $video);
    $imageRoot = ROOT;
    ensure(is_dir($imageRoot) || mkdir($imageRoot, 0755, true), 'Cannot create image scratch.');
    $imageData = imagecreatetruecolor(640, 480);
    ensure($imageData !== false && imagepng($imageData, $imageRoot . '/cover.png'), 'Cannot generate PNG.');
    $image = new ImageEntity('two-command/cover.png', 'png', 'image/png', new PublicId(), (int) filesize($imageRoot . '/cover.png'), 640, 480, 'album');
    $image->setAlbum($album);
    foreach ([$library, $movies, $album, $song, $video, $movie, $movieVideo, $image] as $entity) { $manager->persist($entity); }
    $manager->flush();
    $prepared = ['video' => (string) $video->getPublicId(), 'movie' => (string) $movie->getPublicId(), 'track' => (string) $song->getPublicId(), 'image' => (string) $image->getPublicId()];
    if ($movieEndpoint) {
        $prepared['scenario'] = 'movie-endpoint';
        $prepared['endpoint'] = ['videoEnd' => 42, 'containerEnd' => 48];
    }
    save($prepared);
    $result = state();
} elseif ($mode === 'absent') {
    $state = state(); $auth = $client();
    $video = twoCommandExchange($auth, '/api/transcode/sessions/', 'POST', ['videoId' => $state['video'], 'qualityTier' => '360p']);
    $video->complete(15);
    ensure($video->status() === 503 && $video->header('Retry-After') === '2', 'Missing worker did not refuse video within its bound.');
    // Its sixty-second application deadline needs a client deadline above sixty.
    $endpoint = BASE . '/api/stream/track?id=' . $state['track'] . '&format=mp3&bitrate=64000';
    $handle = curl_init($endpoint);
    curl_setopt_array($handle, [CURLOPT_HTTPHEADER => $auth->proofHeaders('GET', BASE . '/api/stream/track'), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 75]);
    $body = curl_exec($handle); $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    ensure($body !== false && $status >= 500, 'Missing worker did not refuse audio within its bound.');
    curl_close($handle);
    $direct = $auth->open('id=' . $state['track'] . '', ['Range: bytes=0-127'])->complete();
    ensure(in_array($direct->status(), [200, 206], true) && strlen($direct->body) > 0, 'Direct delivery failed without a worker.');
    ensure((new HttpExchange(BASE . '/live', []))->complete()->status() === 200, 'Web liveness failed without a worker.');
    $result = ['videoStatus' => $video->status(), 'audioStatus' => $status, 'directStatus' => $direct->status()];
} elseif ($mode === 'media-control') {
    require __DIR__ . '/media-control.php';
} elseif ($mode === 'capacity') {
    $state = state(); $auth = $client(); $sessions = [];
    foreach (['360p', '480p', '720p'] as $quality) {
        $response = twoCommandExchange($auth, '/api/transcode/sessions/', 'POST', ['videoId' => $state['video'], 'qualityTier' => $quality])->complete(20);
        ensure($response->status() === 201, 'Capacity video admission failed: ' . $response->body);
        $sessions[] = jsonBody($response);
    }
    ensure(count(array_unique(array_column($sessions, 'jobId'))) === 3, 'Capacity needs three distinct video jobs.');
    $audio = $auth->open('id=' . $state['track'] . '&format=mp3&bitrate=192000', timeoutSeconds: 120);
    ensure($audio->until(static fn (HttpExchange $value): bool => strlen($value->body) >= 8192, 30), 'Capacity audio did not start.');
    await(static fn (): bool => (int) $db->fetchOne("SELECT count(*) FROM transcode_runtime_state WHERE claim_token IS NOT NULL") >= 3,
        'three durable video slots');
    ensure((int) $db->fetchOne("SELECT count(*) FROM audio_rendition_runtime WHERE state='running'") >= 1, 'Capacity audio slot is not occupied.');
    $session = $sessions[0];
    foreach (['pause' => 'paused', 'resume' => 'running'] as $action => $status) {
        $response = twoCommandExchange($auth, '/api/transcode/sessions/' . $session['publicId'] . '/' . $action, 'PATCH')->complete(3);
        ensure($response->status() === 200, 'Capacity preparation ' . $action . ' request failed.');
        acknowledged($db, ['job' => $session['jobId']], $status);
    }
    require_once $project . '/tests/Fixtures/Messaging/MessageCodecFactory.php';
    $transport = new Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport(
        Symfony\Component\Messenger\Bridge\Redis\Transport\Connection::fromDsn('redis://default:test-only@redis:6379/messages/baander'),
        new App\Shared\Infrastructure\Messenger\JsonTransportSerializer(App\Tests\Fixtures\Messaging\MessageCodecFactory::create()),
    );
    $redis = new Redis(); $redis->connect('redis', 6379, 1); $redis->auth(['default', 'test-only']);
    $sent = 0;
    $feed = static function () use ($transport, $redis, &$sent): void {
        $needed = max(0, 2000 - $redis->xLen('messages'));
        for ($index = 0; $index < $needed; ++$index) {
            $transport->send(new Symfony\Component\Messenger\Envelope(new App\Shared\Domain\Event\Outbox\RelayOutboxCommand()));
            ++$sent;
        }
    };
    $feed();
    $schedulerJob = $db->fetchOne("SELECT id FROM scheduled_jobs WHERE command='app:transcode:cache-sweep'");
    ensure(is_string($schedulerJob), 'Capacity scheduler fixture missing.');
    $recorder = App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerManualOccurrenceRecorder::fromDsn((string) getenv('DATABASE_URL'));
    $occurrences = []; $nextOccurrence = 0.0; $backlog = [];
    file_put_contents(ROOT . '/capacity-ready', 'ready');
    await(static fn (): bool => is_file(ROOT . '/capacity-pressure-start'), 'capacity pressure barrier', 60);
    $samples = []; $deadline = hrtime(true) + 60_000_000_000; $paused = false;
    while (hrtime(true) < $deadline) {
        $feed();
        $depth = $redis->xLen('messages');
        $backlog[] = ['monotonic' => hrtime(true) / 1e9, 'receiverDepth' => $depth, 'sent' => $sent, 'acknowledged' => $sent - $depth,
            'relayedEvents' => (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox WHERE relayed_at IS NOT NULL')];
        if (hrtime(true) / 1e9 >= $nextOccurrence && hrtime(true) < $deadline - 10_000_000_000) {
            $request = App\Shared\Domain\Model\Uuid::v7();
            ensure($recorder->record(App\Shared\Domain\Model\Uuid::fromString($schedulerJob), $request) !== null, 'Due occurrence was not recorded.');
            $occurrences[] = ['id' => $request->toString(), 'recordedAt' => hrtime(true) / 1e9];
            $nextOccurrence = hrtime(true) / 1e9 + 10;
        }
        $paused = !$paused;
        $startedAt = hrtime(true);
        $session = $sessions[0];
        $response = twoCommandExchange($auth, '/api/transcode/sessions/' . $session['publicId'] . ($paused ? '/pause' : '/resume'), 'PATCH')->complete(3);
        ensure($response->status() === 200, 'Desired state request failed during pressure.');
        $observed = acknowledged($db, ['job' => $session['jobId']], $paused ? 'paused' : 'running');
        $samples[] = ['seconds' => (hrtime(true) - $startedAt) / 1e9, 'desiredRevision' => $observed['desired_revision'], 'observedRevision' => $observed['observed_revision']];
        // Feed real ordinary outbox work, with disabled outbound preferences.
        $user = $manager->getRepository(App\Auth\Infrastructure\Doctrine\Entity\UserEntity::class)->findOneBy(['email' => 'worker-command@baander.app']);
        ensure($user !== null, 'Capacity backlog fixture user missing.');
        $kernel->getContainer()->get('event_dispatcher')->dispatch(new App\Auth\Domain\Event\UserRegistered($user->getId(), $user->getPublicId(), App\Shared\Domain\Model\Email::fromString($user->getEmail()), $user->getName()));
        $audio->until(static fn (HttpExchange $value): bool => $value->done, .01);
        usleep(100000);
    }
    foreach ($occurrences as &$occurrence) {
        $occurrence['execution'] = $db->fetchAssociative('SELECT e.deployment_namespace, e.deployment_boot_id, e.deployment_epoch, EXTRACT(EPOCH FROM (e.returned_at - o.created_at)) AS completion_seconds, e.returned_at IS NOT NULL AS returned FROM scheduler_occurrence_executions e JOIN scheduler_occurrences o ON o.id=e.occurrence_id WHERE o.id=?', [$occurrence['id']]);
    }
    unset($occurrence);
    $result = ['ordinaryBacklog' => $backlog, 'dueOccurrences' => $occurrences, 'videoJobs' => array_column($sessions, 'jobId'), 'desiredStateSamples' => $samples, 'audioBytes' => strlen($audio->body), 'pressureStartedAt' => ($deadline - 60_000_000_000) / 1e9, 'pressureEndedAt' => hrtime(true) / 1e9];
} elseif ($mode === 'attach') {
    $state = state(); $auth = $client();
    $requests = [];
    foreach (range(1, 4) as $_) {
        $requests[] = twoCommandExchange($auth, '/api/transcode/sessions/', 'POST', ['videoId' => $state['video'], 'qualityTier' => '360p']);
    }
    $deadline = microtime(true) + 20;
    do {
        $done = true;
        foreach ($requests as $request) {
            if (!$request->done) { $request->until(static fn (HttpExchange $value): bool => $value->done, .05); }
            $done = $done && $request->done;
        }
    } while (!$done && microtime(true) < $deadline);
    $sessions = [];
    foreach ($requests as $request) {
        ensure($request->done && $request->status() === 201, 'Concurrent attachment failed: ' . $request->body);
        $body = jsonBody($request); $sessions[] = $body['publicId']; $state['job'] = $body['jobId'];
    }
    ensure(count(array_unique($sessions)) === 1, 'Concurrent web attachments did not converge.');
    $state['session'] = $sessions[0]; save($state);
    ensure((int) $db->fetchOne('SELECT count(*) FROM transcode_runtime_state r JOIN transcode_jobs j ON j.id=r.job_id WHERE j.public_id=?', [$state['job']]) === 1, 'Duplicate durable video owner.');
    $decoded = decodeVideo($auth, $state);
    $dash = verifyDash($auth, $state);
    $expiry = verifySignedDeliveryExpiry($auth, $state);
    $state['initialOutput'] = retainVideoOutput($db, $state, $decoded, 'initial'); save($state);
    $audio = $auth->open('id=' . $state['track'] . '&format=mp3&bitrate=192000');
    ensure($audio->until(static fn (HttpExchange $value): bool => strlen($value->body) >= 8192, 30), 'Worker did not produce progressive audio.');
    $audioRow = $db->fetchAssociative("SELECT state, worker_boot_id FROM audio_rendition_runtime WHERE bitrate=192000");
    ensure($audioRow !== false && $audioRow['state'] === 'running' && !$audio->done, 'Audio bytes were not received while the worker encoder was running.');
    $firstBytes = strlen($audio->body);
    ensure($audio->until(static fn (HttpExchange $value): bool => strlen($value->body) > $firstBytes + 8192, 30), 'Worker audio did not grow.');
    $audio->complete(55);
    file_put_contents(ROOT . '/decoded.mp3', $audio->body);
    command(['ffmpeg', '-nostdin', '-v', 'error', '-threads', '1', '-i', ROOT . '/decoded.mp3', '-f', 'null', '-']);
    $image = twoCommandExchange($auth, '/api/images/' . $state['image'] . '/file?preset=thumb')->complete();
    ensure($image->status() === 200 && $image->header('Content-Type') === 'image/png', sprintf('Image request did not return its source while conversion was queued: HTTP %d, Content-Type %s, %d bytes.', $image->status(), $image->header('Content-Type') ?? '(missing)', strlen($image->body)));
    await(static fn (): bool => is_file(ROOT . '/cover_thumb.webp'), 'worker image conversion');
    $converted = twoCommandExchange($auth, '/api/images/' . $state['image'] . '/file?preset=thumb')->complete();
    ensure($converted->status() === 200 && $converted->header('Content-Type') === 'image/webp', sprintf('Worker image conversion is not delivered: HTTP %d, Content-Type %s, %d bytes.', $converted->status(), $converted->header('Content-Type') ?? '(missing)', strlen($converted->body)));
    $recommendation = twoCommandExchange($auth, '/api/admin/recommendations/generate', 'POST', ['mode' => 'full'])->complete();
    ensure($recommendation->status() === 202 && (jsonBody($recommendation)['data']['execution'] ?? null) === 'async', 'Recommendation admission lost its typed asynchronous response.');
    $recommendationId = jsonBody($recommendation)['data']['public_id'];
    await(static function () use ($db, $recommendationId): bool { return $db->fetchOne('SELECT status FROM recommendation_jobs WHERE public_id=?', [$recommendationId]) === 'completed'; }, 'worker recommendation completion', 45);
    $result = ['attachments' => count($requests), 'uniqueSessions' => count(array_unique($sessions)), 'video' => videoEvidence($decoded), 'dash' => $dash, 'signedDeliveryExpiry' => $expiry, 'audioBytes' => strlen($audio->body), 'audioWorkerBoot' => $audioRow['worker_boot_id'], 'imageStatus' => $converted->status(), 'recommendationStatus' => $recommendation->status()];
} elseif ($mode === 'dash') {
    $result = verifyDash($client(), state());
} elseif (in_array($mode, ['seek-paused', 'resume', 'pause-absent', 'cancel'], true)) {
    $state = state(); $auth = $client();
    if ($mode === 'seek-paused' || $mode === 'pause-absent') {
        $pause = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'] . '/pause', 'PATCH')->complete();
        ensure($pause->status() === 200, 'Pause failed: ' . $pause->body);
        if ($mode === 'seek-paused') { acknowledged($db, $state, 'paused'); }
        $before = row($db, $state);
        $seek = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'] . '/position', 'POST', ['position' => 30, 'action' => 'seek'])->complete();
        ensure($seek->status() === 200, 'Seek failed: ' . $seek->body);
        $current = $mode === 'seek-paused' ? acknowledged($db, $state, 'paused', 30) : row($db, $state);
        ensure($current['desired']['status'] === 'paused' && (float) $current['desired']['position'] === 30.0, 'Durable paused seek was lost.');
        $staleGeneration = (int) $current['generation'] - 1;
        ensure($staleGeneration > 0, 'Paused seek has no earlier media generation to reject.');
        $staleSeek = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'] . '/position', 'POST', [
            'position' => 33.246624, 'action' => 'seek', 'generation' => $staleGeneration,
        ])->complete();
        ensure($staleSeek->status() === 200, 'Stale native seek was not handled.');
        $afterStaleSeek = row($db, $state);
        ensure((int) $afterStaleSeek['generation'] === (int) $current['generation']
            && (int) $afterStaleSeek['desired_revision'] === (int) $current['desired_revision']
            && $afterStaleSeek['desired'] === $current['desired'], 'Old media generation overwrote the committed paused seek.');
        $result['staleGenerationIgnored'] = true;
        if ($mode === 'seek-paused') {
            ensure((int) $current['generation'] > (int) $before['generation'], 'Seek did not advance the generation.');
            $result['retiredInitialOutputStatuses'] = rejectRetiredVideoOutput($state['initialOutput']);
        }
        $state['generation'] = (int) $current['generation']; save($state);
    } elseif ($mode === 'resume') {
        $resume = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'] . '/resume', 'PATCH')->complete();
        ensure($resume->status() === 200, 'Resume failed: ' . $resume->body);
        $current = acknowledged($db, $state, 'running', 30);
        $decoded = decodeVideo($auth, $state);
        $result['dash'] = verifyDash($auth, $state);
        $state['preTakeoverOutput'] = retainVideoOutput($db, $state, $decoded, 'pre-takeover'); save($state);
    } else {
        $cancel = twoCommandExchange($auth, '/api/transcode/sessions/' . $state['session'], 'DELETE')->complete();
        ensure($cancel->status() === 200, 'Cancel failed: ' . $cancel->body);
        $current = await(static function () use ($db, $state): array|false { $value = row($db, $state); return $value['outcome'] === 'cancelled' && $value['claim_token'] === null ? $value : false; }, 'cancel releases media owner');
    }
    $result += ['generation' => (int) $current['generation'], 'desired' => $current['desired'], 'desiredRevision' => (int) $current['desired_revision'], 'observedRevision' => (int) $current['observed_revision'], 'outcome' => $current['outcome']];
} elseif ($mode === 'recovered') {
    $state = state(); $auth = $client();
    $current = acknowledged($db, $state, 'paused', 30);
    ensure((int) $current['generation'] >= $state['generation'], 'Recovery regressed generation.');
    $decoded = decodeVideo($auth, $state);
    ensure($decoded['status'] === 'paused' && (float) $decoded['position'] === 30.0, 'Recovered playback lost paused position.');
    ensure((int) $current['generation'] > $state['preTakeoverOutput']['generation'], 'Successor did not advance the retired execution generation.');
    $retiredStatuses = rejectRetiredVideoOutput($state['preTakeoverOutput']);
    $result = ['workerBoot' => $current['worker_boot_id'], 'playback' => videoEvidence($decoded),
        'dash' => verifyDash($auth, $state),
        'retiredGeneration' => $state['preTakeoverOutput']['generation'], 'retiredOutputStatuses' => $retiredStatuses, 'retainedOutputFiles' => count($retiredStatuses)];
} elseif ($mode === 'dependency') {
    $response = twoCommandExchange($client(), '/api/admin/recommendations/generate', 'POST', ['mode' => 'full'])->complete(15);
    ensure($response->status() >= 500, 'Unavailable queue did not refuse admission.');
    ensure((new HttpExchange(BASE . '/live', []))->complete()->status() === 200, 'Queue outage broke web liveness.');
    $result = ['queueAdmissionStatus' => $response->status()];
} else {
    throw new RuntimeException('Unknown drill phase.');
}
// Production logging also uses stdout; keep the phase receipt on its own channel.
$receipt = json_encode(['phase' => $mode, 'seconds' => round(microtime(true) - $started, 3), 'evidence' => $result], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
$receiptPath = ROOT . '/result-' . $mode . '.json';
ensure(file_put_contents($receiptPath, $receipt, LOCK_EX) === strlen($receipt), 'Cannot write phase receipt.');
ensure(chmod($receiptPath, 0600), 'Cannot protect phase receipt.');
echo $receipt;
