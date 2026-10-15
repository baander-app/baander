<?php

declare(strict_types=1);

// Production HTTP/upgrade checks; the runner owns disposable services and credentials.
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\WebRuntime\HttpExchange;
use App\Tests\Fixtures\WebRuntime\StreamClient;
use Doctrine\ORM\EntityManagerInterface;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\CloseFrame;
use Swoole\WebSocket\Frame;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

const WS_SERVER = 'http://127.0.0.1:9501';
const WS_ORIGIN = 'https://baander.app';
const WS_TICKETS = WS_SERVER . '/api/ws/tickets';
const WS_STATE = '/tmp/websocket-runtime-state.json';
const WS_SECRETS = '/tmp/websocket-runtime-secrets.json';

function wsCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $headers */
function wsHttp(string $method, string $path, array $headers): HttpExchange
{
    return (new HttpExchange(WS_SERVER . $path, [...$headers, 'Accept: application/json'], $method))->complete(10);
}

function wsRemember(string $secret): void
{
    $secrets = is_file(WS_SECRETS) ? json_decode((string) file_get_contents(WS_SECRETS), true, flags: JSON_THROW_ON_ERROR) : [];
    $secrets[] = $secret;
    file_put_contents(WS_SECRETS, json_encode($secrets, JSON_THROW_ON_ERROR));
    chmod(WS_SECRETS, 0600);
}

/** Must run inside a coroutine. */
function wsOpen(string $query, ?string $origin = WS_ORIGIN, string $path = '/api/ws'): Client
{
    $ws = new Client('127.0.0.1', 9501);
    $ws->set(['timeout' => 8, 'open_websocket_close_frame' => true, 'open_websocket_pong_frame' => true]);
    if ($origin !== null) {
        $ws->setHeaders(['Origin' => $origin]);
    }
    $ws->upgrade($path . '?' . $query);

    return $ws;
}

function wsStatus(string $query, ?string $origin = WS_ORIGIN, string $path = '/api/ws'): int
{
    $status = 0;
    \Swoole\Coroutine\run(static function () use ($query, $origin, $path, &$status): void {
        $ws = wsOpen($query, $origin, $path);
        try {
            $status = $ws->statusCode;
            if ($status === 101) {
                $frame = $ws->recv(3);
                wsCheck($frame instanceof Frame, 'Upgrade sent no connected frame.');
                $ws->push('', WEBSOCKET_OPCODE_PING);
                $pong = $ws->recv(3);
                wsCheck($pong instanceof Frame && $pong->opcode === WEBSOCKET_OPCODE_PONG, 'Upgraded socket did not answer ping.');
            }
        } finally {
            $ws->close();
        }
    });

    return $status;
}

$mode = $argv[1] ?? 'normal';
if ($mode === 'logs') {
    $secrets = json_decode((string) file_get_contents(WS_SECRETS), true, flags: JSON_THROW_ON_ERROR);
    foreach ([...glob('var/log/*.log'), '/tmp/security-runtime-serve.log'] as $path) {
        $contents = (string) file_get_contents($path);
        foreach ($secrets as $secret) {
            wsCheck(!str_contains($contents, $secret), 'WebSocket credential leaked into ' . basename($path));
        }
    }
    echo "WebSocket runtime: credential log scan passed.\n";
    exit(0);
}
if ($mode === 'outage') {
    $state = json_decode((string) file_get_contents(WS_STATE), true, flags: JSON_THROW_ON_ERROR);
    $start = hrtime(true);
    $response = wsHttp('POST', '/api/ws/tickets', $state['headers']);
    wsCheck($response->status() === 503 && ctype_digit((string) $response->header('Retry-After')), 'Ticket issuance did not fail with retryable 503.');
    $status = wsStatus('ticket=' . $state['ticket']);
    wsCheck($status === 503, 'Ticket redemption did not fail with 503: ' . $status);
    wsCheck((hrtime(true) - $start) / 1e9 < 16, 'Redis outage exceeded request budgets.');
    wsCheck(wsHttp('GET', '/live', [])->status() === 200, 'Server liveness failed during outage.');
    echo "WebSocket runtime: bounded issuance/redemption failure during Redis outage.\n";
    exit(0);
}

$kernel = new App\Kernel('prod', false);
$kernel->boot();
$manager = $kernel->getContainer()->get('doctrine')->getManager();
wsCheck($manager instanceof EntityManagerInterface, 'No entity manager.');
// The fixture uses mapped entities to seed disposable credentials, then the real HTTP firewall.
$email = 'websocket-' . bin2hex(random_bytes(6)) . '@baander.app';
$user = new App\Auth\Infrastructure\Doctrine\Entity\UserEntity(new PublicId(), 'WebSocket runtime', $email, 'test-only-unused-password', '');
$manager->persist($user);
$manager->flush();
$client = new StreamClient($manager, $email, (string) file_get_contents('config/secrets/oauth/private.key'), WS_SERVER . '/api/stream/track');
wsRemember($client->accessToken());
$ticket = static function () use ($client): string {
    $value = $client->webSocketTicket(WS_TICKETS, WS_ORIGIN);
    wsRemember($value);

    return $value;
};
if ($mode === 'prepare-outage') {
    $state = ['ticket' => $ticket(), 'headers' => [...$client->proofHeaders('POST', WS_TICKETS), 'Origin: ' . WS_ORIGIN]];
    file_put_contents(WS_STATE, json_encode($state, JSON_THROW_ON_ERROR));
    chmod(WS_STATE, 0600);
    $kernel->shutdown();
    exit(0);
}
wsCheck($mode === 'normal' || $mode === 'recovered', 'Unknown mode.');
if ($mode === 'recovered') {
    wsCheck(wsStatus('ticket=' . $ticket()) === 101, 'Fresh ticket failed after Redis recovery.');
    $kernel->shutdown();
    echo "WebSocket runtime: fresh issuance and upgrade recovered.\n";
    exit(0);
}

$jwt = $client->accessToken();
foreach ([null, (new SignedDpopProof())->create('POST', WS_TICKETS, $jwt)] as $proof) {
    $headers = ['Authorization: DPoP ' . $jwt, 'Origin: ' . WS_ORIGIN];
    if ($proof !== null) {
        $headers[] = 'DPoP: ' . $proof;
    }
    wsCheck(wsHttp('POST', '/api/ws/tickets', $headers)->status() === 403, 'Ticket issuance accepted absent or wrong proof.');
}
$headers = [...$client->proofHeaders('POST', WS_TICKETS), 'Origin: ' . WS_ORIGIN];
$issued = wsHttp('POST', '/api/ws/tickets', $headers);
wsCheck($issued->status() === 200, 'Fresh proof did not issue a ticket.');
wsCheck(str_contains((string) $issued->header('Cache-Control'), 'no-store'), 'Ticket response is cacheable.');
wsRemember(json_decode($issued->body, true, flags: JSON_THROW_ON_ERROR)['data']['ticket']);
wsCheck(wsHttp('POST', '/api/ws/tickets', $headers)->status() === 403, 'Replayed proof issued a ticket.');
wsCheck(wsStatus('token=' . rawurlencode($jwt)) !== 101, 'Stolen raw access token opened a socket.');

$value = $ticket();
foreach ([null, 'null', 'https://other.baander.app'] as $origin) {
    wsCheck(wsStatus('ticket=' . $value, $origin) === 403, 'Invalid Origin was accepted.');
}
wsCheck(wsStatus('ticket=' . $value, WS_ORIGIN, '/api/party/ws/session') !== 101, 'Wrong upgrade route was accepted.');
foreach (['ticket=' . $value . '&ticket=' . $value, '%74icket=' . $value, 'ticket[]=' . $value, 'ticket=' . $value . '&token=' . $jwt] as $query) {
    wsCheck(wsStatus($query) !== 101, 'Ambiguous credential query was accepted.');
}
wsCheck(wsStatus('ticket=' . $value) === 101, 'Rejected requests consumed the legitimate ticket.');
wsCheck(wsStatus('ticket=' . $value) === 401, 'Consumed ticket was reused.');

$value = $ticket();
\Swoole\Coroutine\run(static function () use ($value): void {
    $results = new Channel(2);
    for ($i = 0; $i < 2; ++$i) {
        \Swoole\Coroutine::create(static function () use ($value, $results): void {
            $ws = wsOpen('ticket=' . $value);
            try {
                $results->push($ws->statusCode);
            } finally {
                $ws->close();
            }
        });
    }
    $statuses = [$results->pop(10), $results->pop(10)];
    sort($statuses);
    wsCheck($statuses === [101, 401], 'Concurrent redemption did not yield exactly one upgrade.');
});

$value = $ticket();
$user->setDisabled(true);
$manager->flush();
wsCheck(wsStatus('ticket=' . $value) === 401, 'Disabled user redeemed a ticket.');
$user->setDisabled(false);
$manager->flush();
$value = $ticket();
sleep(31);
wsCheck(wsStatus('ticket=' . $value) === 401, 'Expired ticket opened a socket.');
$pendingAtLogout = $ticket();
$value = $ticket();
\Swoole\Coroutine\run(static function () use ($value, $client): void {
    $ws = wsOpen('ticket=' . $value);
    try {
        wsCheck($ws->statusCode === 101 && $ws->recv(3) instanceof Frame, 'Logout probe could not open socket.');
        $response = wsHttp('POST', '/api/auth/logout', $client->proofHeaders('POST', WS_SERVER . '/api/auth/logout'));
        wsCheck($response->status() === 200, 'Logout failed.');
        $closed = $ws->recv(5);
        wsCheck($closed instanceof CloseFrame || $closed === '' || ($closed === false && $ws->errCode !== SOCKET_ETIMEDOUT), 'Logout did not close the existing socket.');
    } finally {
        $ws->close();
    }
});
wsCheck(wsStatus('ticket=' . $pendingAtLogout) === 401, 'Revoked token redeemed a pending ticket.');
wsCheck(wsHttp('GET', '/live', [])->status() === 200, 'Server failed fresh liveness check.');
$kernel->shutdown();
echo "WebSocket runtime: proof binding, replay, origin, route, query, single use, concurrency and logout passed.\n";
