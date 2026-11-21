<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\OAuth\DoctrineOAuthTokenInvalidator;
use App\Auth\Interface\Console\RotateSecretsCommand;
use App\Shared\Domain\Model\Email;
use App\Tests\Fixtures\Auth\FirstPartyOAuthClient;
use App\Tests\Fixtures\Auth\OAuthKeyRotationKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Exercise offline key cutover through production signing, authentication, and refresh services. */
final class OAuthKeyRotationAcceptanceTest extends TestCase
{
    private const string ORIGIN = 'https://baander.app';
    private const string PASSWORD = 'oauth-key-rotation-password';

    private string $directory;
    private string $databaseUrl;
    private string $schema;
    private string $ip;
    private string $email;
    private Connection $observer;
    private OAuthKeyRotationKernel $kernel;
    private EntityManagerInterface $manager;
    private SignedDpopProof $proof;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Disposable PostgreSQL and Redis services are required.');
        }
        $this->databaseUrl = $url;
        $this->directory = sys_get_temp_dir() . '/baander-oauth-key-rotation-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->schema = 'oauth_key_rotation_' . bin2hex(random_bytes(8));
        $this->observer = DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url));
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->observer->quoteIdentifier($this->schema));
        $this->observer->executeStatement('SET search_path TO ' . $this->observer->quoteIdentifier($this->schema) . ', public');
        FinalSchemaBootstrap::bootstrap($this->observer);
        $this->ip = sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
        $this->email = 'oauth-key-rotation-' . bin2hex(random_bytes(8)) . '@baander.app';
        $this->proof = new SignedDpopProof();
    }

    protected function tearDown(): void
    {
        $this->stopKernel();
        if (isset($this->observer)) {
            if (isset($this->schema)) {
                $this->observer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->observer->quoteIdentifier($this->schema) . ' CASCADE');
            }
            $this->observer->close();
        }
        if (isset($this->directory)) {
            (new Filesystem())->remove($this->directory);
        }
        parent::tearDown();
    }

    public function testOfflineCutoverRejectsOldSessionsAndAllowsFreshLoginWithTheReplacementKey(): void
    {
        $originalBundle = $this->directory . '/original';
        $replacementBundle = $this->directory . '/replacement';
        $this->bootKernel('before', $originalBundle);
        $this->runRotation('prepare', $originalBundle);
        $container = $this->kernel->getContainer();
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $users->save(User::register(
            new Email($this->email),
            password_hash(self::PASSWORD, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]),
            'OAuth key rotation user',
        ));
        FirstPartyOAuthClient::entity($container, $this->manager);

        $originalJwk = $this->jwk();
        $login = $this->login();
        self::assertSame(200, $this->me($login['accessToken'])->getStatusCode());
        $refreshed = $this->tokens($this->refresh($login['refreshToken']));
        self::assertSame(200, $this->me($refreshed['accessToken'])->getStatusCode());
        $this->runRotation('prepare', $replacementBundle);
        self::assertSame($originalJwk, $this->jwk(), 'Preparing a bundle keeps the active signing key.');

        // Stop the issuer and resource server before the dedicated maintenance invocation.
        $this->stopKernel();
        $this->bootKernel('maintenance', $originalBundle);
        $this->runRotation('invalidate', $replacementBundle);
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_access_tokens'));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_refresh_tokens'));
        $this->stopKernel();

        $this->bootKernel('after', $replacementBundle);
        $replacementJwk = $this->jwk();
        self::assertNotSame($originalJwk['kid'], $replacementJwk['kid']);
        self::assertSame(401, $this->me($refreshed['accessToken'])->getStatusCode());
        self::assertSame(401, $this->refresh($refreshed['refreshToken'])->getStatusCode());

        $fresh = $this->login();
        self::assertSame($replacementJwk['kid'], $this->jwtHeader($fresh['accessToken'])['kid']);
        self::assertSame(200, $this->me($fresh['accessToken'])->getStatusCode());
        $freshRefresh = $this->tokens($this->refresh($fresh['refreshToken']));
        self::assertSame(200, $this->me($freshRefresh['accessToken'])->getStatusCode());
    }

    private function bootKernel(string $phase, string $bundle): void
    {
        $this->kernel = new OAuthKeyRotationKernel(
            $this->directory . '/' . $phase,
            $this->databaseUrl,
            $bundle,
            $this->directory,
        );
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        self::assertSame('prod', $container->getParameter('kernel.environment'));
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $connection = $manager->getConnection();
        $invalidator = $container->get('oauth.rotation.invalidator');
        self::assertInstanceOf(DoctrineOAuthTokenInvalidator::class, $invalidator);
        $invalidationConnection = new ReflectionProperty(DoctrineOAuthTokenInvalidator::class, 'connection')->getValue($invalidator);
        self::assertSame($connection, $invalidationConnection);
        $connection->executeStatement('SET search_path TO ' . $connection->quoteIdentifier($this->schema) . ', public');
        self::assertSame($this->schema, $connection->fetchOne('SELECT current_schema()'));
    }

    private function stopKernel(): void
    {
        if (isset($this->manager)) {
            $this->manager->getConnection()->close();
            $this->manager->close();
            unset($this->manager);
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
            unset($this->kernel);
        }
    }

    private function runRotation(string $action, string $bundle): void
    {
        self::assertSame($this->schema, $this->manager->getConnection()->fetchOne('SELECT current_schema()'));
        $command = $this->kernel->getContainer()->get('oauth.rotation.command');
        self::assertInstanceOf(RotateSecretsCommand::class, $command);
        $tester = new CommandTester($command);
        $input = ['action' => $action, '--directory' => $bundle];
        if ($action === 'invalidate') {
            $input['--offline'] = true;
        }
        self::assertSame(Command::SUCCESS, $tester->execute($input, ['interactive' => false]), $tester->getDisplay());
    }

    /** @return array<string, mixed> */
    private function jwk(): array
    {
        $response = $this->send('GET', '/.well-known/jwks.json');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['keys'][0];
    }

    /** @return array{accessToken: string, refreshToken: string} */
    private function login(): array
    {
        return $this->tokens($this->send('POST', '/api/auth/login', [
            'email' => $this->email,
            'password' => self::PASSWORD,
        ], [
            'DPoP' => $this->proof->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce()),
        ]));
    }

    private function refresh(string $token): Response
    {
        return $this->send('POST', '/api/auth/refresh', ['refreshToken' => $token], [
            'DPoP' => $this->proof->createWithNonce('POST', self::ORIGIN . '/api/auth/refresh', $this->nonce()),
        ]);
    }

    private function nonce(): string
    {
        $response = $this->send('POST', '/api/auth/refresh', ['refreshToken' => 'nonce-probe-' . bin2hex(random_bytes(8))], [
            'DPoP' => $this->proof->create('POST', self::ORIGIN . '/api/auth/refresh', 'no-access-token'),
        ]);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $nonce = $response->headers->get('DPoP-Nonce');
        self::assertIsString($nonce);

        return $nonce;
    }

    private function me(string $token): Response
    {
        return $this->send('GET', '/api/auth/me', headers: [
            'Authorization' => 'DPoP ' . $token,
            'DPoP' => $this->proof->create('GET', self::ORIGIN . '/api/auth/me', $token),
        ]);
    }

    /** @return array{accessToken: string, refreshToken: string} */
    private function tokens(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];

        return ['accessToken' => $data['accessToken'], 'refreshToken' => $data['refreshToken']];
    }

    /** @return array<string, mixed> */
    private function jwtHeader(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts);

        return json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, ?array $json = null, array $headers = []): Response
    {
        $request = Request::create(
            self::ORIGIN . $path,
            $method,
            server: ['REMOTE_ADDR' => $this->ip, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR),
        );
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        if ($this->manager->isOpen()) {
            $this->manager->clear();
        }

        return $response;
    }
}
