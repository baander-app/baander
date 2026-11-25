<?php

declare(strict_types=1);

/*
 * Child process of RequestIdentityInterleaveTest: two users' profile requests overlap in one
 * process, each in a coroutine of its own, as two requests overlap in a web worker. The first
 * request is suspended between the firewall and its controller. The second runs to its end,
 * and its coroutine ends and releases its pooled services, before the first resumes.
 *
 * Arguments: the shared directory (JWT keys and logs of the kernel), then for each of the two
 * requests the path of the DPoP private key (PEM) its session's token was issued to and that
 * access token. Prints the status and the profile's public ID of both answers as JSON, under
 * `suspended` and `completed`.
 */

use App\Tests\Fixtures\Auth\RequestSuspension;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\Auth\SuspendedRequestOAuthKernel;
use Swoole\Coroutine;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// The test process gets its environment from tests/bootstrap.php; a child process
// has only the real environment, so load the same .env files here.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
new Dotenv()->bootEnv(dirname(__DIR__, 3) . '/.env');

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 6) {
    throw new InvalidArgumentException('Expected five arguments.');
}
[, $directory, $suspendedKeyPath, $suspendedToken, $completedKeyPath, $completedToken] = array_map(strval(...), $arguments);
$url = getenv('OUTBOX_TEST_DATABASE_URL');
if (!is_string($url) || $url === '') {
    throw new LogicException('OUTBOX_TEST_DATABASE_URL must name a disposable PostgreSQL database.');
}

$kernel = new SuspendedRequestOAuthKernel($directory, $url);
$kernel->boot();
$container = $kernel->getContainer();
$coroutines = $container->get('oauth.acceptance.co_wrapper');
$suspension = $container->get(RequestSuspension::class);
if (!$coroutines instanceof CoWrapper || !$suspension instanceof RequestSuspension) {
    throw new LogicException('The kernel does not expose the coroutine starter and the request suspension.');
}

/**
 * Sends the profile request of one session through the container's kernel service, the
 * per-coroutine kernel the web server's request handler uses.
 *
 * @return array{status: int, publicId: mixed}
 */
$profile = static function (string $keyPath, string $accessToken, bool $suspend) use ($container): array {
    $uri = 'https://baander.app/api/auth/me';
    $request = Request::create($uri, server: ['HTTP_ACCEPT' => 'application/json']);
    $request->headers->set('Authorization', 'DPoP ' . $accessToken);
    $request->headers->set('DPoP', new SignedDpopProof((string) file_get_contents($keyPath))->create('GET', $uri, $accessToken));
    $request->attributes->set(RequestSuspension::ATTRIBUTE, $suspend);
    $kernel = $container->get('kernel');
    if (!$kernel instanceof KernelInterface || !$kernel instanceof TerminableInterface) {
        throw new LogicException('The container has no terminable kernel.');
    }
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $body = json_decode((string) $response->getContent(), true);

    return ['status' => $response->getStatusCode(), 'publicId' => is_array($body) ? ($body['data']['publicId'] ?? null) : null];
};

$answers = [];
Coroutine\run(static function () use ($coroutines, $suspension, $profile, $suspendedKeyPath, $suspendedToken, $completedKeyPath, $completedToken, &$answers): void {
    $coroutines->go(static function () use ($profile, $suspendedKeyPath, $suspendedToken, &$answers): void {
        $answers['suspended'] = $profile($suspendedKeyPath, $suspendedToken, true);
    });
    $suspension->awaitSuspended();

    $completed = 0;
    $coroutines->go(static function () use ($profile, $completedKeyPath, $completedToken, &$answers, &$completed): void {
        $completed = Coroutine::getCid();
        $answers['completed'] = $profile($completedKeyPath, $completedToken, false);
    });
    // The release of the second request's pooled services runs as its coroutine exits, after
    // its answer is stored, so the first request waits for the exit and not for the answer.
    if (Coroutine::exists($completed) && !Coroutine::join([$completed], 60)) {
        throw new RuntimeException('The second request did not end.');
    }
    $suspension->resume();
});

echo json_encode($answers, JSON_THROW_ON_ERROR);
