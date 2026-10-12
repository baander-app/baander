<?php

declare(strict_types=1);

/*
 * Child process of RefreshTokenConcurrencyTest: presents a refresh token at the production
 * refresh endpoint, or logs a session out, over its own PostgreSQL session, so it can hold
 * or wait on a row lock while the test process runs the other side.
 *
 * Arguments: the shared directory (JWT keys and logs of the kernel), the application name
 * to report in pg_stat_activity, the client IP, the path of the DPoP private key (PEM) the
 * tokens were issued to, the operation (refresh|logout) and the token: the refresh token to
 * present, or the access token of the session to log out. The issuance faults of this
 * process are controlled through IssuanceFaults' environment variable. Prints the status
 * and the decoded body of the answer as JSON.
 */

use App\Tests\Fixtures\Auth\GrantPathOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// The test process gets its environment from tests/bootstrap.php; a child process
// has only the real environment, so load the same .env files here.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__, 3) . '/.env');

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 7) {
    throw new InvalidArgumentException('Expected six arguments.');
}
[, $directory, $application, $ip, $keyPath, $operation, $token] = array_map(strval(...), $arguments);
if (!in_array($operation, ['refresh', 'logout'], true)) {
    throw new InvalidArgumentException('Unknown operation ' . $operation);
}
$url = getenv('OUTBOX_TEST_DATABASE_URL');
if (!is_string($url) || $url === '') {
    throw new LogicException('OUTBOX_TEST_DATABASE_URL must name a disposable PostgreSQL database.');
}

$kernel = new GrantPathOAuthKernel($directory, $url);
$kernel->boot();
$manager = $kernel->getContainer()->get('oauth.acceptance.entity_manager');
if (!$manager instanceof EntityManagerInterface) {
    throw new LogicException('The acceptance entity manager is not exposed.');
}
$connection = $manager->getConnection();
$connection->executeQuery("SELECT set_config('application_name', :name, false)", ['name' => $application])->free();
// Bounds a wait the test does not release; the test fails on the resulting answer. The
// issuing transaction sets its own, shorter, transaction-local bound.
$connection->executeStatement("SET lock_timeout = '10s'");

$origin = 'https://baander.app';
$key = new SignedDpopProof((string) file_get_contents($keyPath));
/** @param array<string, string> $headers */
$send = static function (string $path, ?string $json, array $headers) use ($kernel, $ip, $origin): Response {
    $server = ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json'];
    if ($json !== null) {
        $server['CONTENT_TYPE'] = 'application/json';
    }
    $request = Request::create($origin . $path, 'POST', server: $server, content: $json);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
};

if ($operation === 'refresh') {
    // A nonce-less proof yields the challenge that carries the nonce.
    $challenge = $send('/api/auth/refresh', json_encode(['refreshToken' => 'nonce-probe-' . bin2hex(random_bytes(8))], JSON_THROW_ON_ERROR), [
        'DPoP' => $key->create('POST', $origin . '/api/auth/refresh', 'no-access-token'),
    ]);
    $nonce = $challenge->headers->get('DPoP-Nonce');
    if ($challenge->getStatusCode() !== 400 || !is_string($nonce)) {
        throw new RuntimeException('No nonce challenge: ' . $challenge->getContent());
    }
    $response = $send('/api/auth/refresh', json_encode(['refreshToken' => $token], JSON_THROW_ON_ERROR), [
        'DPoP' => $key->createWithNonce('POST', $origin . '/api/auth/refresh', $nonce),
    ]);
} else {
    $response = $send('/api/auth/logout', null, [
        'Authorization' => 'DPoP ' . $token,
        'DPoP' => $key->create('POST', $origin . '/api/auth/logout', $token),
    ]);
}

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getContent(), true),
], JSON_THROW_ON_ERROR);
