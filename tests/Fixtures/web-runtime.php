<?php

declare(strict_types=1);

/*
 * Steps of scripts/test-web-runtime-container.sh. Runs in the app container, next to
 * app:serve, against the real Swoole server on 127.0.0.1:9501.
 *
 *   prepare  a generated source track in a library, for the stream steps
 *   mail     password-reset email for a user whose saved language is Danish
 *   stream   progressive streaming while a rendition is written; byte ranges once complete
 *   safari   Safari's opening requests against an in-progress rendition; prints the
 *            normalised answers for comparison with tests/Fixtures/WebRuntime/safari-in-progress.txt
 *   alert    the health alerts after a PostgreSQL outage: alert <stopped> <reloaded> <started>
 *            [wait], with the epoch seconds at which the script stopped PostgreSQL, reloaded
 *            the server's workers and started PostgreSQL again; "wait" first waits for the alerts
 */

use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\WebRuntime\HeldRendition;
use App\Tests\Fixtures\WebRuntime\HttpExchange;
use App\Tests\Fixtures\WebRuntime\StreamClient;
use Doctrine\ORM\EntityManagerInterface;

$projectDir = dirname(__DIR__, 2);
require $projectDir . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($projectDir . '/.env');

const SERVER = 'http://127.0.0.1:9501';
const MAILPIT = 'http://mailpit:8025/api/v1';
const STATE_FILE = '/tmp/baander-web-runtime.json';
const SOURCE_PATH = 'web-runtime/track.flac';
const MAIL_USER = 'web-runtime-dansk@baander.app';
const STREAM_USER = 'web-runtime-admin@baander.app';
// The administrators the script creates: an admin and a super-admin.
const ADMINS = ['web-runtime-admin@baander.app', 'web-runtime-super-admin@baander.app'];
// translations/auth+intl-icu.da.yaml, password_reset_email.subject, with APP_NAME as {app}.
const DANISH_RESET_SUBJECT = 'Nulstil din adgangskode til Bånder';
const SAFARI_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function mailpit(string $path): array
{
    $json = file_get_contents(MAILPIT . $path);
    check($json !== false, 'Mailpit did not answer ' . $path);

    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

$mode = $argv[1] ?? '';
set_exception_handler(static function (Throwable $error) use ($mode): never {
    fwrite(STDERR, sprintf("web-runtime %s failed: %s\n  at %s:%d\n", $mode, $error->getMessage(), $error->getFile(), $error->getLine()));
    exit(1);
});
$kernel = new App\Kernel('prod', false);
$kernel->boot();
$manager = $kernel->getContainer()->get('doctrine')->getManager();
check($manager instanceof EntityManagerInterface, 'No entity manager.');
$sourceFile = $projectDir . '/storage/media/' . SOURCE_PATH;
$storageRoot = (string) getenv('CONVERT_STORAGE_PATH');
$streamClient = static fn (): StreamClient => new StreamClient(
    $manager,
    STREAM_USER,
    (string) file_get_contents($projectDir . '/config/secrets/oauth/private.key'),
    SERVER . '/api/stream/track',
);
$track = static function (): string {
    $state = json_decode((string) file_get_contents(STATE_FILE), true, 512, JSON_THROW_ON_ERROR);

    return (string) $state['track'];
};

if ($mode === 'prepare') {
    check(is_dir(dirname($sourceFile)) || mkdir(dirname($sourceFile), 0755, true), 'Cannot create the library directory.');
    exec(sprintf(
        'ffmpeg -nostdin -hide_banner -loglevel error -y -f lavfi -i %s -ac 2 -ar 44100 -c:a flac %s 2>&1',
        escapeshellarg('sine=frequency=440:duration=5'),
        escapeshellarg($sourceFile),
    ), $output, $code);
    check($code === 0, 'ffmpeg could not generate the source: ' . implode("\n", $output));

    $library = new LibraryEntity('Web runtime', 'web-runtime', dirname($sourceFile), 'music', 'local');
    $album = new AlbumEntity(new PublicId(), $library, 'Web runtime album', 'album');
    $song = new SongEntity(new PublicId(), $album, 'Web runtime song', SOURCE_PATH, (int) filesize($sourceFile), 'audio/flac');
    foreach ([$library, $album, $song] as $entity) {
        $manager->persist($entity);
    }
    $manager->flush();
    file_put_contents(STATE_FILE, json_encode(['track' => $song->getPublicId()->toString()], JSON_THROW_ON_ERROR));
    echo "Prepared a generated FLAC track in a library.\n";
} elseif ($mode === 'mail') {
    $request = (new HttpExchange(
        SERVER . '/api/auth/password/reset-request',
        ['Content-Type: application/json', 'Accept: application/json'],
        'POST',
        json_encode(['email' => MAIL_USER], JSON_THROW_ON_ERROR),
    ))->complete();
    check($request->status() === 200, 'The reset request answered ' . $request->status() . ': ' . $request->body);

    // The email leaves at kernel.terminate, after the response.
    $messages = [];
    for ($attempt = 0; $attempt < 100 && $messages === []; ++$attempt) {
        usleep(200_000);
        $messages = array_values(array_filter(
            mailpit('/messages')['messages'] ?? [],
            static fn (array $message): bool => in_array(MAIL_USER, array_column($message['To'] ?? [], 'Address'), true),
        ));
    }
    check(count($messages) === 1, sprintf('Expected one email to %s, got %d.', MAIL_USER, count($messages)));
    $message = mailpit('/message/' . $messages[0]['ID']);
    check($message['Subject'] === DANISH_RESET_SUBJECT, sprintf('Expected the subject "%s", got "%s".', DANISH_RESET_SUBJECT, $message['Subject']));
    check(preg_match('/<html[^>]*\slang="da"/', (string) $message['HTML']) === 1, 'The email HTML is not marked lang="da".');
    echo "The password-reset email arrived in Danish.\n";
} elseif ($mode === 'stream') {
    $client = $streamClient();
    $id = $track();

    // In progress: this process holds the encoder lock and writes the partial file.
    $held = new HeldRendition($storageRoot, $id, $sourceFile, 'mp3', 64);
    $held->append(HeldRendition::bytes(16_384, 'first'));
    $received = static fn (HttpExchange $exchange): bool => strlen($exchange->body) >= strlen($held->written);
    $first = $client->open('id=' . $id . '&format=mp3&bitrate=64000');
    check($first->until($received), sprintf(
        'The in-progress rendition did not stream its first bytes (status %d, %d of %d bytes).',
        $first->status(), strlen($first->body), strlen($held->written),
    ));
    check($first->status() === 200, 'The in-progress rendition answered ' . $first->status());
    check($first->header('Accept-Ranges') === 'none', 'The in-progress rendition answered Accept-Ranges: ' . var_export($first->header('Accept-Ranges'), true));
    check($first->header('Content-Type') === 'audio/mpeg', 'The in-progress rendition answered Content-Type: ' . var_export($first->header('Content-Type'), true));
    $held->append(HeldRendition::bytes(16_384, 'second'));
    check($first->until($received), sprintf(
        'The body did not grow while the rendition was written (%d of %d bytes).',
        strlen($first->body), strlen($held->written),
    ));
    check(!$first->done, 'The response ended while the rendition was still being written.');
    $held->append(HeldRendition::bytes(8_192, 'third'));
    $held->complete();
    $first->complete();
    check($first->body === $held->written, sprintf(
        'The streamed body (%d bytes) differs from the rendition written (%d bytes).',
        strlen($first->body), strlen($held->written),
    ));
    echo "An in-progress rendition streamed with Accept-Ranges: none and grew as it was written.\n";

    // A real encode, run by the server's CPU process pool.
    $encoded = $client->open('id=' . $id . '&format=mp3&bitrate=128000')->complete();
    check($encoded->status() === 200, 'The encode answered ' . $encoded->status() . ': ' . $encoded->body);
    check($encoded->header('Accept-Ranges') === 'none', 'The first answer during an encode was not progressive.');
    $probeFile = (string) tempnam(sys_get_temp_dir(), 'web-runtime-');
    file_put_contents($probeFile, $encoded->body);
    exec(sprintf('ffprobe -v error -select_streams a:0 -show_entries stream=codec_name -of csv=p=0 %s 2>&1', escapeshellarg($probeFile)), $probe, $code);
    unlink($probeFile);
    check($code === 0 && $probe === ['mp3'], 'The encoded body is not MP3 audio: ' . implode("\n", $probe));
    check(strlen($encoded->body) > 200, 'The encoded body is too short for the range check.');

    $range = $client->open('id=' . $id . '&format=mp3&bitrate=128000', ['Range: bytes=100-199'])->complete();
    check($range->status() === 206, 'The complete rendition answered Range: bytes=100-199 with ' . $range->status());
    check(
        $range->header('Content-Range') === sprintf('bytes 100-199/%d', strlen($encoded->body)),
        'The complete rendition answered Content-Range: ' . var_export($range->header('Content-Range'), true),
    );
    check($range->body === substr($encoded->body, 100, 100), 'The range body differs from bytes 100-199 of the encoded rendition.');
    echo "The server encoded a rendition progressively, then answered bytes=100-199 with 206.\n";
} elseif ($mode === 'safari') {
    $client = $streamClient();
    $id = $track();
    $held = new HeldRendition($storageRoot, $id, $sourceFile, 'mp3', 96);
    $held->append(HeldRendition::bytes(98_304, 'safari'));
    $session = strtoupper((new Uuid())->toString());
    $transcript = [
        '# Answers to Safari\'s opening requests for an MP3 rendition that is still encoding.',
        '# Written by tests/Fixtures/web-runtime.php safari; dates and DPoP nonces are normalised.',
        '# Each request: GET /api/stream/track?format=mp3, User-Agent: Safari 17.4 (macOS), Accept: */*,',
        '# X-Playback-Session-Id: <one id per sequence>, DPoP-bound Authorization.',
        '',
    ];
    foreach (['bytes=0-1', 'bytes=0-65535'] as $byteRange) {
        // An in-progress answer streams until the encode ends, so stop at the first body byte.
        $exchange = $client->open('id=' . $id . '&format=mp3&bitrate=96000', [
            'User-Agent: ' . SAFARI_USER_AGENT,
            'Accept: */*',
            'Range: ' . $byteRange,
            'X-Playback-Session-Id: ' . $session,
        ], stopAfterBytes: 1);
        check(
            $exchange->until(static fn (HttpExchange $exchange): bool => $exchange->body !== '' || $exchange->done),
            'No answer to Range: ' . $byteRange,
        );
        $transcript[] = '> Range: ' . $byteRange;
        foreach ($exchange->headerLines as $line) {
            $transcript[] = '< ' . preg_replace(['/^(Date):.*/i', '/^(DPoP-Nonce):.*/i'], ['$1: <date>', '$1: <nonce>'], $line);
        }
        $transcript[] = '';
    }
    $held->complete();
    echo implode("\n", $transcript);
} elseif ($mode === 'alert') {
    $arguments = array_slice($argv ?? [], 2);
    [$stoppedAt, $reloadedAt, $startedAt] = array_map(intval(...), array_pad(array_slice($arguments, 0, 3), 3, 0));
    $wait = ($arguments[3] ?? '') === 'wait';
    check($stoppedAt > 0 && $reloadedAt >= $stoppedAt && $startedAt >= $reloadedAt, 'Usage: alert <stopped> <reloaded> <started> [wait]');
    $interval = (int) getenv('HEALTH_MONITOR_INTERVAL_SECONDS');
    check($interval > 0, 'HEALTH_MONITOR_INTERVAL_SECONDS is not set.');
    $connection = $manager->getConnection();
    $alerts = static fn (): array => $connection->fetchAllAssociative(
        "SELECT u.email, n.title, n.body, n.reference_data FROM notifications n JOIN users u ON u.id = n.user_id
          WHERE n.event_type = 'admin.health_degraded' ORDER BY u.email, n.created_at",
    );
    $rows = $alerts();
    // Delivery saves one row per administrator, one after the other.
    $deadline = time() + 10 * $interval;
    while ($wait && count($rows) < count(ADMINS) && time() < $deadline) {
        sleep(1);
        $rows = $alerts();
    }

    $describe = static fn (array $row): string => sprintf('%s: "%s" %s', $row['email'], $row['title'], $row['reference_data']);
    check(
        array_column($rows, 'email') === ADMINS,
        sprintf("Expected one health alert for each of %s, got %d:\n%s", implode(', ', ADMINS), count($rows), implode("\n", array_map($describe, $rows))),
    );
    foreach ($rows as $row) {
        $reference = json_decode((string) $row['reference_data'], true, 512, JSON_THROW_ON_ERROR);
        check($reference === ['component' => 'postgresql'] && $row['title'] === 'postgresql health degraded', 'Not a PostgreSQL alert: ' . $describe($row));
        // Delivered after PostgreSQL returned, the alert names the outage window.
        check(
            preg_match('/^postgresql was unhealthy from (.+) until (.+)\. This alert could not be delivered during the outage\.$/', (string) $row['body'], $window) === 1,
            'The alert does not name the outage window: ' . $row['body'],
        );
        $from = (new DateTimeImmutable($window[1]))->getTimestamp();
        $until = (new DateTimeImmutable($window[2]))->getTimestamp();
        // An outage that began after the reload would mean the reload lost the owed alert.
        check($from >= $stoppedAt && $from <= $reloadedAt, sprintf(
            'The outage began at %s, not between the stop (%s) and the reload (%s).',
            $window[1], gmdate('H:i:s', $stoppedAt), gmdate('H:i:s', $reloadedAt),
        ));
        check($until >= $startedAt && $until <= time(), sprintf(
            'The outage ended at %s, not between the start of PostgreSQL (%s) and now.',
            $window[2], gmdate('H:i:s', $startedAt),
        ));
    }
    echo sprintf("Each administrator has one PostgreSQL alert, naming the outage: %s\n", $rows[0]['body']);
} else {
    throw new RuntimeException('Unknown web runtime-test mode.');
}

$kernel->shutdown();
