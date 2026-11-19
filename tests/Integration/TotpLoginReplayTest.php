<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\IssuanceFaults;
use App\Tests\Fixtures\Auth\ProductionOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Password login with a TOTP code through the production firewall, on real PostgreSQL
 * and Redis: the web client's sequence of a nonce challenge and a retry with the same
 * code, single use of each code, the encrypted secret at rest, and two logins racing
 * with one code (the second runs in a child process, totp-login-race.php).
 */
final class TotpLoginReplayTest extends TestCase
{
    private const string ORIGIN = 'https://baander.app';
    private const string PASSWORD = 'totp-login-test-password';
    private const int PERIOD = 30;

    private static string $sharedDirectory;
    private ProductionOAuthKernel $kernel;
    private Connection $connection;
    private User $user;
    private string $ip;
    private string $secret;
    /** The step the enable request spent. */
    private int $enabledAt;
    /** @var list<string> */
    private array $temporaryFiles = [];

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$sharedDirectory)) {
            (new Filesystem())->remove(self::$sharedDirectory);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Disposable PostgreSQL and Redis services are required.');
        }
        if (!isset(self::$sharedDirectory)) {
            self::$sharedDirectory = sys_get_temp_dir() . '/baander-totp-login-' . bin2hex(random_bytes(8));
            self::assertTrue(mkdir(self::$sharedDirectory, 0700));
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            self::assertNotFalse($key);
            self::assertTrue(openssl_pkey_export($key, $privateKey));
            $details = openssl_pkey_get_details($key);
            self::assertIsArray($details);
            self::assertSame(strlen($privateKey), file_put_contents(self::$sharedDirectory . '/private.pem', $privateKey));
            self::assertSame(strlen($details['key']), file_put_contents(self::$sharedDirectory . '/public.pem', $details['key']));
            self::assertTrue(chmod(self::$sharedDirectory . '/private.pem', 0600));
            self::assertTrue(chmod(self::$sharedDirectory . '/public.pem', 0600));
        }
        $this->kernel = new ProductionOAuthKernel(self::$sharedDirectory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->connection = $manager->getConnection();
        // Login limits are per client IP; isolate each test's budget.
        $this->ip = sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));

        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->user = User::createByOperator(
            new Email('totp-login-' . bin2hex(random_bytes(8)) . '@baander.app'),
            password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'TOTP login user',
            ['ROLE_USER'],
        );
        $users->save($this->user);

        $spaClientId = $container->getParameter('auth.spa_client_id');
        self::assertIsString($spaClientId);
        if ($this->connection->fetchOne('SELECT 1 FROM oauth_clients WHERE public_id = ?', [$spaClientId]) === false) {
            $manager->persist(new ClientEntity(
                PublicId::fromString($spaClientId),
                'TOTP login SPA',
                json_encode([self::ORIGIN . '/callback'], JSON_THROW_ON_ERROR),
                passwordClient: true,
                firstParty: true,
            ));
            $manager->flush();
        }
        $this->enrol();
    }

    protected function tearDown(): void
    {
        if (isset($this->user)) {
            $userId = $this->user->getId()->toString();
            $this->connection->executeStatement(
                "DELETE FROM domain_event_outbox WHERE payload->>'user_id' = :userId",
                ['userId' => $userId],
            );
            // Refresh tokens and token metadata cascade from access tokens.
            $this->connection->executeStatement('DELETE FROM oauth_access_tokens WHERE user_id = ?', [$userId]);
            $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [$userId]);
        }
        $this->kernel->shutdown();
        (new Filesystem())->remove($this->temporaryFiles);
        parent::tearDown();
    }

    /**
     * The web client signs in with a proof that has no nonce, is challenged, and retries
     * with the same code: that retry signs in, and a third request with the code fails.
     */
    public function testANonceChallengeLeavesTheCodeValidForExactlyOneRetry(): void
    {
        $key = new SignedDpopProof();
        $code = $this->code($this->enabledAt + 1);

        $challenge = $this->login($code, $key->create('POST', self::ORIGIN . '/api/auth/login', 'no-access-token'));
        self::assertSame(400, $challenge->getStatusCode(), (string) $challenge->getContent());
        $nonce = $challenge->headers->get('DPoP-Nonce');
        self::assertIsString($nonce);

        $retry = $this->login($code, $key->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $nonce));
        self::assertSame(200, $retry->getStatusCode(), (string) $retry->getContent());

        $replay = $this->login($code, $key->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce()));
        self::assertSame(401, $replay->getStatusCode(), (string) $replay->getContent());
        self::assertSame('AUTH_INVALID_CREDENTIALS', $this->errorCode($replay));
        self::assertSame(1, $this->sessions());
    }

    public function testAnEmptyCodeAsksForTheCode(): void
    {
        $response = $this->login('', (new SignedDpopProof())->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce()));

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('AUTH_TOTP_REQUIRED', $this->errorCode($response));
    }

    public function testTheRowHoldsCiphertextOfTheEnrolledSecretAndNoPlaintextColumn(): void
    {
        $stored = $this->connection->fetchOne(
            'SELECT totp_secret_ciphertext FROM users WHERE id = ?',
            [$this->user->getId()->toString()],
        );
        $stored = is_resource($stored) ? stream_get_contents($stored) : $stored;
        self::assertIsString($stored);
        self::assertStringNotContainsString($this->secret, $stored);
        self::assertSame($this->secret, self::open($stored, self::configuredKey()));

        self::assertSame(
            ['totp_last_used_step', 'totp_secret_ciphertext'],
            $this->connection->fetchFirstColumn(
                "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'users' AND column_name LIKE 'totp%' ORDER BY column_name",
            ),
        );
    }

    /** A secret the configured key cannot open is never read as TOTP being off. */
    public function testASecretUnderAnotherKeyAnswersUnavailableAndIssuesNothing(): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET totp_secret_ciphertext = :ciphertext WHERE id = :id',
            ['ciphertext' => self::seal($this->secret, sodium_crypto_secretbox_keygen()), 'id' => $this->user->getId()->toString()],
            ['ciphertext' => ParameterType::BINARY],
        );

        // Without a code the account still asks for one; the code then meets the unreadable secret.
        foreach ([[null, 'AUTH_TOTP_REQUIRED'], [$this->code($this->enabledAt + 1), 'AUTH_TOTP_UNAVAILABLE']] as [$code, $answer]) {
            $response = $this->login($code, (new SignedDpopProof())->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce()));
            self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame($answer, $this->errorCode($response));
        }
        self::assertSame(0, $this->sessions());
    }

    /**
     * Login A spends the code's step and stalls in the signer with the users row locked;
     * login B, with the same code, waits on that row. A signs in; B finds the step spent.
     */
    public function testTwoConcurrentLoginsWithOneCodeIssueOneSession(): void
    {
        $code = $this->code($this->enabledAt + 1);
        $stalled = $this->faultDirectory();
        touch(IssuanceFaults::path($stalled, IssuanceFaults::SIGN_BLOCK));
        $first = $this->startChild($code, $stalled);
        $this->awaitFlag($first, $stalled);
        $second = $this->startChild($code, $this->faultDirectory());
        $this->awaitLockWait($second);
        self::assertTrue(unlink(IssuanceFaults::path($stalled, IssuanceFaults::SIGN_BLOCK)));

        $answers = [$this->finish($first), $this->finish($second)];

        self::assertSame([200, 401], [$answers[0]['status'], $answers[1]['status']], json_encode($answers, JSON_THROW_ON_ERROR));
        self::assertSame('AUTH_INVALID_CREDENTIALS', $answers[1]['body']['error']['code'] ?? null);
        self::assertSame(1, $this->sessions());
    }

    // --- Operator reset ---

    /**
     * `app:user:reset-totp` in a process of its own: stdout carries only the user, the sessions
     * end, and the user signs in with the password alone. A second reset has nothing to do.
     */
    public function testAnOperatorResetEndsTheSessionsAndLetsThePasswordAloneSignIn(): void
    {
        $key = new SignedDpopProof();
        $before = $this->login($this->code($this->enabledAt + 1), $key->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce()));
        self::assertSame(200, $before->getStatusCode(), (string) $before->getContent());

        $reset = $this->console('app:user:reset-totp', $this->user->getEmail(), '--json');

        self::assertSame(0, $reset->getExitCode(), $reset->getErrorOutput());
        $printed = json_decode($reset->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($this->user->getPublicId()->toString(), $printed['publicId']);
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM oauth_access_tokens WHERE user_id = ? AND NOT revoked',
            [$this->user->getId()->toString()],
        ));
        self::assertNull($this->connection->fetchOne('SELECT totp_secret_ciphertext FROM users WHERE id = ?', [$this->user->getId()->toString()]));
        $after = $this->login(null, (new SignedDpopProof())->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce()));
        self::assertSame(200, $after->getStatusCode(), (string) $after->getContent());

        $again = $this->console('app:user:reset-totp', $this->user->getEmail(), '--json');
        self::assertSame(1, $again->getExitCode());
        self::assertSame('', $again->getOutput());
        self::assertStringContainsString('TOTP is not on', $again->getErrorOutput());
    }

    private function console(string ...$arguments): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/console', ...$arguments], dirname(__DIR__, 2), timeout: 120);
        $process->run();

        return $process;
    }

    // --- Enrolment ---    // --- Enrolment ---

    /** Turns TOTP on through the API, as the settings page does. */
    private function enrol(): void
    {
        $key = new SignedDpopProof();
        $login = $this->login(null, $key->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce()));
        self::assertSame(200, $login->getStatusCode(), (string) $login->getContent());
        $accessToken = $this->json($login)['data']['accessToken'];

        $setup = $this->authorized('/api/auth/totp/setup', $accessToken, $key, ['currentPassword' => self::PASSWORD]);
        self::assertSame(200, $setup->getStatusCode(), (string) $setup->getContent());
        $this->secret = $this->json($setup)['data']['secret'];

        $this->enabledAt = $this->currentStep();
        $enable = $this->authorized('/api/auth/totp/enable', $accessToken, $key, ['code' => $this->code($this->enabledAt)]);
        self::assertSame(200, $enable->getStatusCode(), (string) $enable->getContent());
        // Leave the enable request's step behind, so every login below uses a later code.
        $this->connection->executeStatement('DELETE FROM oauth_access_tokens WHERE user_id = ?', [$this->user->getId()->toString()]);
    }

    private function code(int $step): string
    {
        return TOTP::create($this->secret)->at($step * self::PERIOD);
    }

    private function currentStep(): int
    {
        return intdiv(time(), self::PERIOD);
    }

    private static function configuredKey(): string
    {
        $encoded = $_SERVER['AUTH_TOTP_ENCRYPTION_KEY'] ?? $_ENV['AUTH_TOTP_ENCRYPTION_KEY'] ?? getenv('AUTH_TOTP_ENCRYPTION_KEY');
        self::assertIsString($encoded);
        $key = base64_decode($encoded, true);
        self::assertIsString($key);

        return $key;
    }

    private static function seal(string $plaintext, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce . sodium_crypto_secretbox($plaintext, $nonce, $key);
    }

    private static function open(string $sealed, string $key): string|false
    {
        return sodium_crypto_secretbox_open(
            substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );
    }

    /** Access tokens issued to the user. */
    private function sessions(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM oauth_access_tokens WHERE user_id = ?',
            [$this->user->getId()->toString()],
        );
    }

    // --- Requests ---

    private function login(?string $code, string $proof): Response
    {
        $body = ['email' => $this->user->getEmail(), 'password' => self::PASSWORD];
        if ($code !== null) {
            $body['totpCode'] = $code;
        }

        return $this->send('/api/auth/login', $body, ['DPoP' => $proof]);
    }

    /** @param array<string, mixed> $json */
    private function authorized(string $path, string $accessToken, SignedDpopProof $key, array $json): Response
    {
        return $this->send($path, $json, [
            'Authorization' => 'DPoP ' . $accessToken,
            'DPoP' => $key->create('POST', self::ORIGIN . $path, $accessToken),
        ]);
    }

    /** Nonces are single-use and endpoint-independent; a nonce-less proof yields a fresh challenge. */
    private function nonce(): string
    {
        $probe = new SignedDpopProof();
        $response = $this->send('/api/auth/refresh', ['refreshToken' => 'nonce-probe-' . bin2hex(random_bytes(8))], [
            'DPoP' => $probe->create('POST', self::ORIGIN . '/api/auth/refresh', 'no-access-token'),
        ]);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $nonce = $response->headers->get('DPoP-Nonce');
        self::assertIsString($nonce);

        return $nonce;
    }

    /**
     * @param array<string, mixed> $json
     * @param array<string, string> $headers
     */
    private function send(string $path, array $json, array $headers): Response
    {
        $request = Request::create(self::ORIGIN . $path, 'POST', server: [
            'REMOTE_ADDR' => $this->ip,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode($json, JSON_THROW_ON_ERROR));
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        $this->releasePooledServices();

        return $response;
    }

    /** Releases what a request took from the service pools, as the web server does when its coroutine ends. */
    private function releasePooledServices(): void
    {
        $pools = $this->kernel->getContainer()->get('oauth.acceptance.service_pools');
        self::assertInstanceOf(ServicePoolContainer::class, $pools);
        $swoole = $this->kernel->getContainer()->get('oauth.acceptance.swoole');
        self::assertInstanceOf(Swoole::class, $swoole);
        // The EntityManager resetter reports each reset through error_log().
        $errorLog = ini_set('error_log', '/dev/null');
        try {
            $pools->releaseFromCoroutine($swoole->getCoroutineId());
        } finally {
            ini_set('error_log', $errorLog === false ? '' : $errorLog);
        }
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function errorCode(Response $response): mixed
    {
        return $this->json($response)['error']['code'] ?? null;
    }

    // --- Child processes ---

    private function faultDirectory(): string
    {
        $directory = self::$sharedDirectory . '/faults-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $this->temporaryFiles[] = $directory;

        return $directory;
    }

    /** @return array{process: resource, output: resource, application: string} */
    private function startChild(string $code, string $faultDirectory): array
    {
        $application = 'totp_login_race_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        self::assertIsResource($output);
        $environment = getenv();
        $environment[IssuanceFaults::ENVIRONMENT_VARIABLE] = $faultDirectory;
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Auth/totp-login-race.php', self::$sharedDirectory, $application, $this->ip, $this->user->getEmail(), self::PASSWORD, $code],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
            null,
            $environment,
        );
        self::assertIsResource($process);

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @param array{process: resource, output: resource, application: string} $child */
    private function awaitFlag(array $child, string $directory): void
    {
        $deadline = microtime(true) + 20;
        while (!IssuanceFaults::isSet($directory, IssuanceFaults::SIGN_BLOCKED)) {
            if (microtime(true) > $deadline || !proc_get_status($child['process'])['running']) {
                rewind($child['output']);
                self::fail('The first login must stall in the signer: ' . stream_get_contents($child['output']));
            }
            usleep(5000);
        }
    }

    /** @param array{process: resource, output: resource, application: string} $child */
    private function awaitLockWait(array $child): void
    {
        $deadline = microtime(true) + 20;
        while (true) {
            $this->connection->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            $waiting = $this->connection->fetchOne(
                "SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'",
                [$child['application']],
            );
            if ($waiting !== false) {
                return;
            }
            if (microtime(true) > $deadline || !proc_get_status($child['process'])['running']) {
                rewind($child['output']);
                self::fail('The second login must wait on the users row: ' . stream_get_contents($child['output']));
            }
            usleep(5000);
        }
    }

    /**
     * @param array{process: resource, output: resource, application: string} $child
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function finish(array $child): array
    {
        $deadline = microtime(true) + 30;
        while (proc_get_status($child['process'])['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($child['process'], 9);
                self::fail('The child did not finish.');
            }
            usleep(5000);
        }
        rewind($child['output']);
        $result = (string) stream_get_contents($child['output']);
        fclose($child['output']);
        self::assertSame(0, proc_close($child['process']), $result);
        $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsInt($decoded['status'] ?? null, $result);

        /** @var array{status: int, body: array<string, mixed>} $decoded */
        return $decoded;
    }
}
