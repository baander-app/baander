<?php

declare(strict_types=1);
use App\Kernel;
use App\Shared\Application\Http\PublicOrigins;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Symfony\Component\Dotenv\Dotenv;

// Raw HTTP probes run next to app:serve on a disposable, isolated container network.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
new Dotenv()->bootEnv(dirname(__DIR__, 2) . '/.env');

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function exchange(string $wire): string
{
    $socket = stream_socket_client('tcp://127.0.0.1:9501', $errno, $error, 3);
    check($socket !== false, 'Cannot connect to the runtime.');
    stream_set_timeout($socket, 3);
    $offset = 0;
    while ($offset < strlen($wire)) {
        $written = fwrite($socket, substr($wire, $offset));
        check($written !== false && $written > 0, 'Request write failed.');
        $offset += $written;
    }
    $answer = stream_get_contents($socket, 65537);
    $metadata = stream_get_meta_data($socket);
    fclose($socket);
    check(!$metadata['timed_out'], 'HTTP rejection exceeded the socket deadline.');
    check($answer !== false && strlen($answer) <= 65536, 'Unbounded HTTP response.');

    return $answer;
}

function status(string $answer): int
{
    return preg_match('/^HTTP\/1\.[01] ([0-9]{3})/', $answer, $matches) === 1 ? (int) $matches[1] : 0;
}

function healthy(): void
{
    check(exchange("GET /live HTTP/1.1\r\nHost: baander.app\r\nConnection: close\r\n\r\n") |> status(...) === 200, 'Server did not recover on a fresh connection.');
}

/** The nonce the token endpoint hands out when it refuses a request without a proof. */
function dpopNonce(): string
{
    $answer = exchange("POST /api/oauth/token HTTP/1.1\r\nHost: baander.app\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    check(preg_match('/^DPoP-Nonce: *([0-9a-f]+)\r$/mi', $answer, $matches) === 1, 'The token endpoint handed out no DPoP nonce.');

    return $matches[1];
}

/** 256 random bytes as an odd 2048-bit number. */
function odd2048BitNumber(): string
{
    return random_bytes(256) | ("\x80" . str_repeat("\0", 254) . "\x01");
}

/**
 * A token request whose `PS256` proof carries an RSA key the sender made up: a 2048-bit
 * modulus and the given exponent. The nonce and every claim are in order, and the
 * signature is as long as the modulus and numerically below it, so nothing but the
 * algorithm or the key refuses the proof.
 */
function rsaProofRequest(string $origin, string $nonce, string $exponent): string
{
    $encode = SignedDpopProof::encode(...);
    $header = ['typ' => 'dpop+jwt', 'alg' => 'PS256', 'jwk' => ['kty' => 'RSA', 'n' => $encode(odd2048BitNumber()), 'e' => $encode($exponent)]];
    $claims = ['jti' => random_bytes(16) |> bin2hex(...), 'htm' => 'POST', 'htu' => $origin . '/api/oauth/token', 'iat' => time(), 'nonce' => $nonce];
    $proof = $encode(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . $encode(json_encode($claims, JSON_THROW_ON_ERROR)) . '.' . $encode("\0" . random_bytes(255));

    return "POST /api/oauth/token HTTP/1.1\r\nHost: baander.app\r\nDPoP: " . $proof . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
}

$kernel = new Kernel('prod', false);
$kernel->boot();
$connection = $kernel->getContainer()->get('doctrine')->getConnection();
$tables = ['users', 'oauth_access_tokens', 'oauth_refresh_tokens', 'password_reset_tokens', 'login_blocks'];
$snapshot = static function () use ($connection, $tables): array {
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = $connection->fetchOne('SELECT count(*) FROM ' . $table);
    }

    return $counts;
};
$before = $snapshot();
$failures = [];
$cases = 0;
$probe = static function (string $name, string $wire, array $allowed, ?float $deadline = null, ?string $error = null) use (&$cases, &$failures): void {
    ++$cases;
    try {
        $started = hrtime(true);
        $answer = exchange($wire);
        $seconds = (hrtime(true) - $started) / 1e9;
        $answerStatus = status($answer);
        check(in_array($answerStatus, $allowed, true), $name . ' answered ' . status($answer));
        check($deadline === null || $seconds < $deadline, sprintf('%s was answered after %.2f seconds', $name, $seconds));
        check($error === null || str_contains($answer, '"' . $error . '"'), $name . ' was not refused as ' . $error);
        check(!str_contains($answer, 'security-runtime-secret-canary'), $name . ' reflected a credential');
        check(!preg_match('/(?:Stack trace|vendor\/|SQLSTATE|"access_token"|"refresh_token")/', $answer), $name . ' disclosed internals or credentials');
        healthy();
        echo $name . ": rejected; fresh connection healthy.\n";
    } catch (Throwable $error) {
        $failures[] = $error->getMessage();
        fwrite(STDERR, $error->getMessage() . "\n");
    }
};
$index = 0;
foreach (['{' , 'null', '42', '[]', '{"email":[],"password":"security-runtime-secret-canary"}', '{"email":"invalid","password":{}}', '{"email":"user@baander.app","password":{}}', '{"email":"user@baander.app","password":"security-runtime-secret-canary","totpCode":[]}', '{"username":[],"email":"user@baander.app"}'] as $body) {
    $probe('login JSON ' . ++$index, "POST /api/auth/login HTTP/1.1\r\nHost: baander.app\r\nContent-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body, [400, 401, 422]);
}
foreach (['{', '{"email":[]}', '{"email":null}'] as $body) {
    $probe('reset JSON ' . ++$index, "POST /api/auth/password/reset-request HTTP/1.1\r\nHost: baander.app\r\nContent-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body, [400, 422]);
}
$probe('invalid Content-Length', "POST /api/auth/login HTTP/1.1\r\nHost: baander.app\r\nContent-Length: invalid\r\nConnection: close\r\n\r\n", [0, 400]);
$probe('invalid request line', "GET /live NOTHTTP\r\nHost: baander.app\r\nConnection: close\r\n\r\n", [0, 400]);
$probe('oversized declared body', "POST /api/auth/login HTTP/1.1\r\nHost: baander.app\r\nContent-Length: 16777217\r\nConnection: close\r\n\r\n", [413]);
// Swoole takes this body in full; the application refuses it before anything decodes it, and the counts compared below
// show that no login block was stored for its filled honeypot.
$signInBody = (static function (): string {
    [$prefix, $suffix] = ['{"email":"user@baander.app","password":"wrong","username":"', '"}'];

    return $prefix . str_repeat('a', 2_000_000 - strlen($prefix) - strlen($suffix)) . $suffix;
})();
$probe('oversized sign-in body', "POST /api/auth/login HTTP/1.1\r\nHost: baander.app\r\nContent-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\nContent-Length: " . strlen($signInBody) . "\r\n\r\n" . $signInBody, [413]);
// A worker that verifies an RSA proof in pure-PHP arithmetic is held for minutes by the first exponent and for over a second
// by the second, the one ordinary keys have. The error named shows the nonce was accepted and the proof itself refused.
$origin = new PublicOrigins((string) $kernel->getContainer()->getParameter('auth.oauth.issuer'))->primary;
foreach (['a 2048-bit exponent' => odd2048BitNumber(), 'the exponent 65537' => "\x01\x00\x01"] as $name => $exponent) {
    $probe('RSA proof with ' . $name, rsaProofRequest($origin, dpopNonce(), $exponent), [400], deadline: 1.0, error: 'invalid_dpop_proof');
}
check($before === $snapshot(), 'Rejected input changed credential or user counts.');
$kernel->shutdown();
check($failures === [], implode('; ', $failures));
echo sprintf("Security runtime: %d rejection cases; credential/user counts unchanged.\n", $cases);
