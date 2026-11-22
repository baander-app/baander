<?php

declare(strict_types=1);

/*
 * Child process of PasswordChangeSessionRevocationTest: sends one request a signed-in session
 * makes, such as a password change or the completion of a passkey registration, to the test's
 * production kernel over its own PostgreSQL session, so it can wait on the account's row lock
 * while the test process holds a competing change open.
 *
 * Arguments: the shared directory (JWT keys and logs of the kernel), the application name to
 * report in pg_stat_activity, the client IP, the path of the DPoP private key (PEM) the
 * session's tokens were issued to, the session's access token, the method, the path and the
 * JSON body. Prints the status and the decoded body of the answer as JSON.
 */

use App\Tests\Fixtures\Auth\PasswordChangeOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// The test process gets its environment from tests/bootstrap.php; a child process
// has only the real environment, so load the same .env files here.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__, 3) . '/.env');

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 9) {
    throw new InvalidArgumentException('Expected eight arguments.');
}
[, $directory, $application, $ip, $keyPath, $accessToken, $method, $path, $json] = array_map(strval(...), $arguments);
$url = getenv('OUTBOX_TEST_DATABASE_URL');
if (!is_string($url) || $url === '') {
    throw new LogicException('OUTBOX_TEST_DATABASE_URL must name a disposable PostgreSQL database.');
}

// The test's own kernel, so a passkey registration the test started verifies against the same relying party.
$kernel = new PasswordChangeOAuthKernel($directory, $url);
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
$request = Request::create($origin . $path, $method, server: [
    'REMOTE_ADDR' => $ip,
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
], content: $json);
$request->headers->set('Authorization', 'DPoP ' . $accessToken);
$request->headers->set('DPoP', $key->create($method, $origin . $path, $accessToken));
$response = $kernel->handle($request);
$kernel->terminate($request, $response);

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getContent(), true),
], JSON_THROW_ON_ERROR);
