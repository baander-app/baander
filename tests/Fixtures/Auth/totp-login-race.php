<?php

declare(strict_types=1);

/*
 * Child process of TotpLoginReplayTest: signs in with a password and a TOTP code at the
 * production login endpoint over its own PostgreSQL session, so it can hold or wait on
 * the users row lock while another login with the same code runs.
 *
 * Arguments: the shared directory (JWT keys and logs of the kernel), the application name
 * to report in pg_stat_activity, the client IP, the email, the password and the code. The
 * issuance faults of this process are controlled through IssuanceFaults' environment
 * variable. Prints the status and the decoded body of the answer as JSON.
 */

use App\Tests\Fixtures\Auth\ProductionOAuthKernel;
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
[, $directory, $application, $ip, $email, $password, $code] = array_map(strval(...), $arguments);
$url = getenv('OUTBOX_TEST_DATABASE_URL');
if (!is_string($url) || $url === '') {
    throw new LogicException('OUTBOX_TEST_DATABASE_URL must name a disposable PostgreSQL database.');
}

$kernel = new ProductionOAuthKernel($directory, $url);
$kernel->boot();
$manager = $kernel->getContainer()->get('oauth.acceptance.entity_manager');
if (!$manager instanceof EntityManagerInterface) {
    throw new LogicException('The acceptance entity manager is not exposed.');
}
$manager->getConnection()->executeQuery("SELECT set_config('application_name', :name, false)", ['name' => $application])->free();

$origin = 'https://baander.app';
$key = new SignedDpopProof();
/** @param array<string, string> $headers */
$send = static function (string $path, string $json, array $headers) use ($kernel, $ip, $origin): Response {
    $request = Request::create($origin . $path, 'POST', server: [
        'REMOTE_ADDR' => $ip,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], content: $json);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
};

// A nonce-less proof yields the challenge that carries the nonce.
$credentials = json_encode(['email' => $email, 'password' => $password, 'totpCode' => $code], JSON_THROW_ON_ERROR);
$challenge = $send('/api/auth/login', $credentials, ['DPoP' => $key->create('POST', $origin . '/api/auth/login', 'no-access-token')]);
$nonce = $challenge->headers->get('DPoP-Nonce');
if ($challenge->getStatusCode() !== 400 || !is_string($nonce)) {
    throw new RuntimeException('No nonce challenge: ' . $challenge->getContent());
}
$response = $send('/api/auth/login', $credentials, ['DPoP' => $key->createWithNonce('POST', $origin . '/api/auth/login', $nonce)]);

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getContent(), true),
], JSON_THROW_ON_ERROR);
