<?php

declare(strict_types=1);

/*
 * Redis outage steps of scripts/test-web-runtime-container.sh. Runs in the app container,
 * next to app:serve, against the real Swoole server on 127.0.0.1:9501, over HTTP only; the
 * session (its DPoP key, token pair and next nonce) is kept in a state file between steps.
 *
 *   login      sign in with a fresh DPoP key, as the web app does: a nonce challenge, then the login
 *   outage     while Redis is stopped: a refresh of the session and a DPoP-bound API call both answer
 *              503 with Retry-After, no DPoP-Nonce and no WWW-Authenticate, inside the web client's
 *              per-attempt timeout
 *   recovered  after Redis is back: the same refresh token refreshes, taking one nonce challenge if
 *              the restart lost the stored nonce, and the new access token is accepted
 */

use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\WebRuntime\HttpExchange;

$projectDir = dirname(__DIR__, 2);
require $projectDir . '/vendor/autoload.php';

const SERVER = 'http://127.0.0.1:9501';
const STATE_FILE = '/tmp/baander-web-runtime-session.json';
const USER = 'web-runtime-admin@baander.app';
const PASSWORD = 'Web-runtime-test-only-2';
// ui/web/src/shared/auth/refresh-budget.ts: a 503 must arrive inside one attempt.
const CLIENT_ATTEMPT_SECONDS = 10.0;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param list<string> $headers
 * @param array<string, mixed>|null $json
 */
function send(string $method, string $path, array $headers, ?array $json = null): HttpExchange
{
    return (new HttpExchange(SERVER . $path, [
        ...$headers,
        'Accept: application/json',
        ...($json === null ? [] : ['Content-Type: application/json']),
    ], $method, $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR)))->complete();
}

function describe(HttpExchange $exchange): string
{
    return sprintf('%d %s', $exchange->status(), $exchange->body);
}

/** @return array<string, mixed> */
function state(): array
{
    $json = file_get_contents(STATE_FILE);
    check($json !== false, 'No session; run the login step first.');

    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $state */
function save(array $state): void
{
    file_put_contents(STATE_FILE, json_encode($state, JSON_THROW_ON_ERROR));
}

/** A nonce-less proof at the refresh endpoint yields a challenge; nonces are endpoint-independent. */
function challengeNonce(): string
{
    $probe = new SignedDpopProof();
    $response = send('POST', '/api/auth/refresh', [
        'DPoP: ' . $probe->create('POST', SERVER . '/api/auth/refresh', 'no-access-token'),
    ], ['refreshToken' => 'nonce-probe-' . bin2hex(random_bytes(8))]);
    check($response->status() === 400, 'The nonce probe answered ' . describe($response));
    $nonce = $response->header('DPoP-Nonce');
    check(is_string($nonce) && $nonce !== '', 'The nonce probe carried no DPoP-Nonce header.');

    return $nonce;
}

/** @return array{status: int, exchange: HttpExchange, seconds: float} */
function refresh(SignedDpopProof $key, string $refreshToken, string $nonce): array
{
    $start = microtime(true);
    $exchange = send('POST', '/api/auth/refresh', [
        'DPoP: ' . $key->createWithNonce('POST', SERVER . '/api/auth/refresh', $nonce),
    ], ['refreshToken' => $refreshToken]);

    return ['status' => $exchange->status(), 'exchange' => $exchange, 'seconds' => microtime(true) - $start];
}

function me(SignedDpopProof $key, string $accessToken): HttpExchange
{
    return send('GET', '/api/auth/me', [
        'Authorization: DPoP ' . $accessToken,
        'DPoP: ' . $key->create('GET', SERVER . '/api/auth/me', $accessToken),
    ]);
}

/** The answer while the DPoP store is unavailable: 503, a retry delay, nothing cached, no nonce and no refusal. */
function checkUnavailable(HttpExchange $exchange, string $what): void
{
    check($exchange->status() === 503, $what . ' answered ' . describe($exchange));
    check(ctype_digit((string) $exchange->header('Retry-After')), $what . ' answered 503 without a Retry-After delay.');
    // Symfony adds "private" to a response that names no cacheability.
    check(str_contains((string) $exchange->header('Cache-Control'), 'no-store'), $what . ' answered Cache-Control: ' . var_export($exchange->header('Cache-Control'), true));
    check($exchange->header('DPoP-Nonce') === null, $what . ' answered 503 with a DPoP-Nonce header.');
    check($exchange->header('WWW-Authenticate') === null, $what . ' answered 503 with a WWW-Authenticate header.');
}

$mode = $argv[1] ?? '';
set_exception_handler(static function (Throwable $error) use ($mode): never {
    fwrite(STDERR, sprintf("redis-outage %s failed: %s\n  at %s:%d\n", $mode, $error->getMessage(), $error->getFile(), $error->getLine()));
    exit(1);
});

if ($mode === 'login') {
    $pem = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $privateKey = null;
    if ($pem === false || !openssl_pkey_export($pem, $privateKey) || !is_string($privateKey)) {
        throw new RuntimeException('Cannot generate the session key.');
    }
    $key = new SignedDpopProof($privateKey);
    $login = send('POST', '/api/auth/login', [
        'DPoP: ' . $key->createWithNonce('POST', SERVER . '/api/auth/login', challengeNonce()),
    ], ['email' => USER, 'password' => PASSWORD]);
    check($login->status() === 200, 'The login answered ' . describe($login));
    $data = json_decode($login->body, true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
    $nonce = $login->header('DPoP-Nonce');
    check(is_string($data['accessToken'] ?? null) && is_string($data['refreshToken'] ?? null), 'The login issued no token pair: ' . $login->body);
    check(is_string($nonce) && $nonce !== '', 'The login carried no DPoP-Nonce for the next proof.');
    save([
        'privateKey' => $privateKey,
        'accessToken' => $data['accessToken'],
        'refreshToken' => $data['refreshToken'],
        'nonce' => $nonce,
    ]);
    echo sprintf("Signed in as %s with a DPoP-bound token pair and the next nonce.\n", USER);
} elseif ($mode === 'outage') {
    $state = state();
    $key = new SignedDpopProof($state['privateKey']);

    $refresh = refresh($key, $state['refreshToken'], $state['nonce']);
    checkUnavailable($refresh['exchange'], 'The refresh during the outage');
    check($refresh['seconds'] < CLIENT_ATTEMPT_SECONDS, sprintf(
        'The refresh during the outage took %.1f s, longer than the client attempt of %.0f s.',
        $refresh['seconds'], CLIENT_ATTEMPT_SECONDS,
    ));
    echo sprintf(
        "The refresh during the outage answered 503 with Retry-After: %s and no DPoP-Nonce in %.2f s.\n",
        $refresh['exchange']->header('Retry-After'), $refresh['seconds'],
    );

    $start = microtime(true);
    $me = me($key, $state['accessToken']);
    $seconds = microtime(true) - $start;
    check($me->status() !== 403, 'GET /api/auth/me during the outage was refused (403) instead of told to wait: ' . describe($me));
    checkUnavailable($me, 'GET /api/auth/me during the outage');
    check($seconds < CLIENT_ATTEMPT_SECONDS, sprintf('GET /api/auth/me during the outage took %.1f s.', $seconds));
    echo sprintf(
        "GET /api/auth/me during the outage answered 503 with Retry-After: %s, not 403, in %.2f s.\n",
        $me->header('Retry-After'), $seconds,
    );
} elseif ($mode === 'recovered') {
    $state = state();
    $key = new SignedDpopProof($state['privateKey']);

    // The web client's nonce retry: a restart may have lost the nonce the login issued.
    $refresh = refresh($key, $state['refreshToken'], $state['nonce']);
    $challenged = false;
    if ($refresh['status'] === 400 && is_string($refresh['exchange']->header('DPoP-Nonce'))) {
        $challenged = true;
        $refresh = refresh($key, $state['refreshToken'], (string) $refresh['exchange']->header('DPoP-Nonce'));
    }
    check($refresh['status'] === 200, 'The refresh after the outage answered ' . describe($refresh['exchange']));
    $data = json_decode($refresh['exchange']->body, true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
    check(is_string($data['accessToken'] ?? null) && is_string($data['refreshToken'] ?? null), 'The refresh issued no token pair: ' . $refresh['exchange']->body);
    check($data['refreshToken'] !== $state['refreshToken'], 'The refresh did not rotate the refresh token.');
    check(is_string($refresh['exchange']->header('DPoP-Nonce')), 'The refresh after the outage carried no DPoP-Nonce for the next proof.');

    $me = me($key, $data['accessToken']);
    check($me->status() === 200, 'GET /api/auth/me with the new access token answered ' . describe($me));
    save([...$state, 'accessToken' => $data['accessToken'], 'refreshToken' => $data['refreshToken'], 'nonce' => $refresh['exchange']->header('DPoP-Nonce')]);
    echo sprintf(
        "The same refresh token refreshed after Redis returned%s, and the new access token is accepted.\n",
        $challenged ? ' after one nonce challenge' : ' with the nonce the login issued',
    );
} else {
    throw new RuntimeException('Unknown redis-outage mode.');
}
