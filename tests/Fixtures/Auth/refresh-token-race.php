<?php

declare(strict_types=1);

/*
 * Child process of RefreshTokenGraceTest: presents a refresh token at the production
 * refresh endpoint over its own PostgreSQL session, so it can wait on a row lock the
 * test process holds.
 *
 * Arguments: the shared directory (JWT keys and logs of the kernel), the application
 * name to report in pg_stat_activity, the client IP, the path of the DPoP private key
 * (PEM) the token was issued to, and the refresh token. Prints the status and the
 * decoded body of the refresh answer as JSON.
 */

use App\Tests\Fixtures\Auth\GrantPathOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 6) {
    throw new InvalidArgumentException('Expected five arguments.');
}
[, $directory, $application, $ip, $keyPath, $refreshToken] = array_map(strval(...), $arguments);
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
// Bounds a wait the test does not release; the test fails on the resulting answer.
$connection->executeStatement("SET lock_timeout = '10s'");

$origin = 'https://baander.app';
$key = new SignedDpopProof((string) file_get_contents($keyPath));
$send = static function (string $token, string $proof) use ($kernel, $ip, $origin): Response {
    $request = Request::create(
        $origin . '/api/auth/refresh',
        'POST',
        server: ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
        content: json_encode(['refreshToken' => $token], JSON_THROW_ON_ERROR),
    );
    $request->headers->set('DPoP', $proof);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
};

// A nonce-less proof yields the challenge that carries the nonce.
$challenge = $send('nonce-probe-' . bin2hex(random_bytes(8)), $key->create('POST', $origin . '/api/auth/refresh', 'no-access-token'));
$nonce = $challenge->headers->get('DPoP-Nonce');
if ($challenge->getStatusCode() !== 400 || !is_string($nonce)) {
    throw new RuntimeException('No nonce challenge: ' . $challenge->getContent());
}

$response = $send($refreshToken, $key->createWithNonce('POST', $origin . '/api/auth/refresh', $nonce));

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getContent(), true),
], JSON_THROW_ON_ERROR);
