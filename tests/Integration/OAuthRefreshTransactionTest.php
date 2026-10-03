<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Application\ScopeAllowlist;
use App\Auth\Infrastructure\Adapter\OAuth as Adapter;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\RefreshTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Auth\Infrastructure\Doctrine\UserRepository;
use App\Auth\Infrastructure\Repository\OAuth as Repository;
use App\Auth\Infrastructure\Security\OAuth\AuthorizationServerFactory;
use App\Shared\Domain\Model\PublicId;
use App\Auth\Domain\Model\OAuth\Client;
use App\Shared\Infrastructure\Doctrine\DoctrineTransaction;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DatabaseException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use League\OAuth2\Server\AuthorizationServer;
use App\Auth\Infrastructure\Security\OAuth\ResourceServerFactory;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\InvalidKeyProvided;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/** Real League refresh protocol and migration tables; no custom refresh handler. */
final class OAuthRefreshTransactionTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private string $schema;
    private string $keyDirectory;
    private string $encryptionKey;
    private string $clientId;
    private string $refreshToken;
    private string $oldRefreshId;
    private string $oldAccessId;
    private string $userId;
    private ?string $proofJkt = 'test-jkt';

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->writer = DriverManager::getConnection($params);
        $this->observer = DriverManager::getConnection($params);
        self::assertStringStartsWith('18.', (string) $this->writer->fetchOne('SHOW server_version'));
        $this->writer->executeStatement('CREATE EXTENSION IF NOT EXISTS citext');
        $this->schema = 'oauth_refresh_test_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->writer, $this->observer] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        }
        require_once dirname(__DIR__, 2) . '/migrations/Version001_InitialSchema.php';
        $migration = new \DoctrineMigrations\Version001_InitialSchema($this->writer, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $sql = $query->getStatement();
            if (preg_match('/\ACREATE TABLE (?:oauth_[a-z_]+|users)\b/', $sql) === 1
                || preg_match('/\AALTER TABLE oauth_[a-z_]+\b/', $sql) === 1
                || preg_match('/\ACREATE (?:UNIQUE )?INDEX [a-z_]+ ON oauth_[a-z_]+\b/', $sql) === 1) {
                // The trigram indexes are unrelated to refresh behavior and require pg_trgm.
                if (!str_contains($sql, 'gin_trgm_ops')) {
                    $this->writer->executeStatement($sql, $query->getParameters(), $query->getTypes());
                }
            }
        }
        $this->manager = self::manager($this->writer);
        $this->keyDirectory = sys_get_temp_dir() . '/oauth-refresh-keys-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->keyDirectory, 0700));
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($rsa);
        self::assertTrue(openssl_pkey_export($rsa, $private));
        $details = openssl_pkey_get_details($rsa);
        self::assertNotFalse($details);
        file_put_contents($this->keyDirectory . '/private.pem', $private);
        file_put_contents($this->keyDirectory . '/public.pem', $details['key']);
        chmod($this->keyDirectory . '/private.pem', 0600);
        chmod($this->keyDirectory . '/public.pem', 0600);
        $key = Key::createNewRandomKey();
        $this->encryptionKey = $key->saveToAsciiSafeString();
        $client = new ClientEntity(new PublicId(), 'Refresh integration', '["https://baander.app/callback"]');
        $this->clientId = $client->getIdentifier();
        $this->oldAccessId = bin2hex(random_bytes(40));
        $this->oldRefreshId = bin2hex(random_bytes(40));
        $expiry = new \DateTimeImmutable('+1 hour');
        $user = new UserEntity(new PublicId(), 'Refresh user', 'refresh-user@baander.app', 'stored-password-hash', '');
        $this->userId = $user->getId()->toString();
        $access = new AccessTokenEntity($this->oldAccessId, $client, $user, expiresAt: $expiry);
        $access->setDpopJkt('test-jkt');
        $refresh = new RefreshTokenEntity($this->oldRefreshId, $access, $expiry);
        foreach ([$client, $user, $access, $refresh] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();
        $this->manager->clear();
        $this->refreshToken = Crypto::encrypt(json_encode([
            'client_id' => $this->clientId, 'refresh_token_id' => $this->oldRefreshId,
            'access_token_id' => $this->oldAccessId, 'scopes' => [], 'user_id' => $this->userId,
            'expire_time' => $expiry->getTimestamp(),
        ], JSON_THROW_ON_ERROR), $key);
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
        }
        if (isset($this->manager)) {
            $this->manager->clear();
        }
        if (isset($this->schema)) {
            $this->observer->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        if (isset($this->writer)) {
            $this->writer->close();
            $this->observer->close();
        }
        if (isset($this->keyDirectory)) {
            foreach (['private.pem', 'public.pem'] as $file) {
                unlink($this->keyDirectory . '/' . $file);
            }
            rmdir($this->keyDirectory);
        }
    }

    public function testReplacementPersistenceFailureRollsBackRevocationAndConsumption(): void
    {
        $this->writer->executeStatement(<<<'SQL'
            CREATE FUNCTION reject_replacement() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'injected replacement persistence failure'; END $$
            SQL);
        $this->writer->executeStatement('CREATE TRIGGER reject_replacement BEFORE INSERT ON oauth_refresh_tokens FOR EACH ROW EXECUTE FUNCTION reject_replacement()');
        try {
            $this->refresh($this->server());
            self::fail('Real replacement INSERT must fail.');
        } catch (\Throwable $error) {
            self::assertInstanceOf(DatabaseException::class, $error);
        }
        $this->assertOriginalActiveOnly();
        $this->writer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        $this->writer->executeStatement('DROP TRIGGER reject_replacement ON oauth_refresh_tokens');
        // A failed flush closes its EntityManager. Recovery uses a fresh request manager.
        $this->manager = self::manager($this->writer);
        $replacement = $this->refresh($this->server());
        self::assertArrayHasKey('refresh_token', $replacement);
    }

    public function testResponseSigningFailureRollsBackPersistedReplacements(): void
    {
        try {
            $this->refresh($this->server('public.pem'));
            self::fail('An RSA public key cannot sign the access JWT.');
        } catch (\Throwable $error) {
            self::assertInstanceOf(InvalidKeyProvided::class, $error);
        }
        $this->assertOriginalActiveOnly();
        $this->writer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        self::assertArrayHasKey('access_token', $this->refresh($this->server()));
    }

    public function testReturnedReplacementRefreshTokenWorksAndOldTokenIsRejected(): void
    {
        $server = $this->server();
        $first = $this->refresh($server);
        self::assertSame('DPoP', $first['token_type']);
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_refresh_tokens WHERE token_id = ? AND revoked = TRUE AND used_at IS NOT NULL', [$this->oldRefreshId]));
        self::assertNotSame($this->refreshToken, $first['refresh_token']);
        $this->manager->clear();
        $second = $this->refresh($server, (string) $first['refresh_token']);
        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        self::assertNotSame($first['access_token'], $second['access_token']);
        $resource = (new ResourceServerFactory($this->accessAdapter(), $this->keyDirectory . '/public.pem', 'https://baander.app'))->create();
        $authenticated = $resource->validateAuthenticatedRequest((new ServerRequest('GET', 'https://baander.app/api/me'))->withHeader('Authorization', 'Bearer ' . $second['access_token']));
        self::assertIsString($authenticated->getAttribute('oauth_access_token_id'));
        self::assertSame($this->userId, $authenticated->getAttribute('oauth_user_id'));
        self::assertSame($this->observer->fetchOne('SELECT id FROM oauth_clients WHERE public_id = ?', [$this->clientId]), $authenticated->getAttribute('oauth_client_id'));
        $jwt = (new Parser(new JoseEncoder()))->parse((string) $second['access_token']);
        self::assertInstanceOf(Plain::class, $jwt);
        self::assertSame(['jkt' => 'test-jkt'], $jwt->claims()->get('cnf'));
        self::assertSame($this->userId, $jwt->claims()->get('sub'));
        $firstJwt = (new Parser(new JoseEncoder()))->parse((string) $first['access_token']);
        self::assertInstanceOf(Plain::class, $firstJwt);
        self::assertNotSame($firstJwt->claims()->get('jti'), $jwt->claims()->get('jti'));
        self::assertSame($authenticated->getAttribute('oauth_access_token_id'), $jwt->claims()->get('jti'));
        self::assertSame(['https://baander.app'], $jwt->claims()->get('aud'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_access_tokens WHERE token_id = ? AND revoked = FALSE AND dpop_jkt = ?', [$authenticated->getAttribute('oauth_access_token_id'), 'test-jkt']));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_refresh_tokens WHERE revoked = FALSE AND used_at IS NULL'));
        $this->expectException(OAuthServerException::class);
        $this->refresh($server);
    }

    public function testMissingOrWrongProofBindingDoesNotConsumeOriginalToken(): void
    {
        foreach ([null, 'wrong-jkt'] as $jkt) {
            $this->proofJkt = $jkt;
            try {
                $this->refresh($this->server());
                self::fail('Refresh must be bound to the original DPoP key.');
            } catch (OAuthServerException) {
                $this->assertOriginalActiveOnly();
            }
            $this->writer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        }
        $this->proofJkt = 'test-jkt';
        self::assertArrayHasKey('refresh_token', $this->refresh($this->server()));
    }

    public function testUnboundOriginalAccessTokenCannotBeRefreshed(): void
    {
        $this->writer->executeStatement('UPDATE oauth_access_tokens SET dpop_jkt = NULL WHERE token_id = ?', [$this->oldAccessId]);
        try {
            $this->refresh($this->server());
            self::fail('An unbound refresh grant must not become bound on first use.');
        } catch (OAuthServerException) {
            $this->assertOriginalActiveOnly();
        }
    }

    public function testSimultaneousRefreshRequestsProduceOnlyOneReplacement(): void
    {
        $lock = random_int(100000, 2000000000);
        $this->writer->executeStatement('CREATE FUNCTION hold_replacement() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_advisory_xact_lock(' . $lock . '); RETURN NEW; END $$');
        $this->writer->executeStatement('CREATE TRIGGER hold_replacement BEFORE INSERT ON oauth_access_tokens FOR EACH ROW EXECUTE FUNCTION hold_replacement()');
        $this->observer->fetchOne('SELECT pg_advisory_lock(?)', [$lock]);
        $processes = [];
        try {
            $firstName = $this->schema . '_first';
            $secondName = $this->schema . '_second';
            $processes[] = $this->startContender($firstName);
            $this->awaitDatabaseLock($firstName, 'advisory');
            $processes[] = $this->startContender($secondName);
            $this->awaitDatabaseLock($secondName, 'transactionid');
            $this->observer->fetchOne('SELECT pg_advisory_unlock(?)', [$lock]);
            $first = $this->finishContender($processes[0]);
            $second = $this->finishContender($processes[1]);
            self::assertSame('issued', $first);
            self::assertSame('rejected', $second);
            self::assertSame(2, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_access_tokens'));
            self::assertSame(2, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_refresh_tokens'));
            self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_refresh_tokens WHERE revoked = FALSE AND used_at IS NULL'));
            self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_refresh_tokens WHERE token_id = ? AND revoked = TRUE AND used_at IS NOT NULL', [$this->oldRefreshId]));
        } finally {
            $this->observer->fetchOne('SELECT pg_advisory_unlock(?)', [$lock]);
            foreach ($processes as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($process);
                }
            }
        }
    }

    /** @return array{resource,array<int,resource>} */
    private function startContender(string $application): array
    {
        $script = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
            . '; require ' . var_export(__FILE__, true) . '; '
            . self::class . '::runContender();';
        $process = proc_open([PHP_BINARY, '-r', $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode([
            'schema' => $this->schema, 'keys' => $this->keyDirectory, 'encryption' => $this->encryptionKey,
            'client' => $this->clientId, 'refresh' => $this->refreshToken, 'application' => $application,
        ], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        unset($pipes[0]);
        return [$process, $pipes];
    }

    /** @param array{resource,array<int,resource>} $contender */
    private function finishContender(array $contender): string
    {
        [$process, $pipes] = $contender;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        self::assertSame(0, proc_close($process), 'Contender must finish successfully.');
        self::assertSame('', $stderr);
        self::assertIsString($stdout);
        return $stdout;
    }

    private function awaitDatabaseLock(string $application, string $event): void
    {
        $deadline = microtime(true) + 4;
        do {
            if ((int) $this->observer->fetchOne('SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = ? AND wait_event = ?', [$application, 'Lock', $event]) > 0) {
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        self::fail('The independent refresh request did not reach its expected PostgreSQL lock.');
    }

    public static function runContender(): void
    {
        $data = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse((string) getenv('OUTBOX_TEST_DATABASE_URL'));
        $connection = DriverManager::getConnection($params);
        $connection->executeStatement('SET search_path TO ' . $data['schema'] . ', public');
        $connection->fetchOne("SELECT set_config('application_name', ?, false)", [$data['application']]);
        $test = new self('testReturnedReplacementRefreshTokenWorksAndOldTokenIsRejected');
        $test->writer = $connection;
        $test->manager = self::manager($connection);
        $test->keyDirectory = $data['keys'];
        $test->encryptionKey = $data['encryption'];
        $test->clientId = $data['client'];
        $test->refreshToken = $data['refresh'];
        try {
            $test->refresh($test->server());
            echo 'issued';
        } catch (OAuthServerException) {
            echo 'rejected';
        } catch (\Throwable) {
            echo 'unexpected_failure';
            exit(1);
        }
        $connection->close();
    }

    public function testClientSaveLoadAndResavePreservePersistentUuid(): void
    {
        $repository = new Repository\ClientRepository($this->manager, new JsonEncoder());
        $client = Client::create('Persistent client', ['https://baander.app/callback']);
        $repository->saveClient($client);
        $this->manager->clear();
        $loaded = $repository->findClientByPublicId($client->getPublicId());
        self::assertNotNull($loaded);
        self::assertSame($client->getId()->toString(), $loaded->getId()->toString());
        $repository->saveClient($loaded);
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_clients WHERE public_id = ?', [$client->getPublicId()->toString()]));
        $leagueClient = (new Adapter\ClientRepository($repository, new JsonEncoder()))->getClientEntity($client->getPublicId()->toString());
        self::assertInstanceOf(ClientEntity::class, $leagueClient);
        self::assertSame($client->getId()->toString(), $leagueClient->getId()->toString());
    }

    private function assertOriginalActiveOnly(): void
    {
        self::assertFalse($this->writer->isTransactionActive());
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_access_tokens'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_refresh_tokens'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_access_tokens WHERE revoked = FALSE'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_refresh_tokens WHERE revoked = FALSE AND used_at IS NULL'));
    }

    public static function manager(Connection $connection): EntityManager
    {
        CustomTypesRegistrar::register();
        $configuration = ORMSetup::createAttributeMetadataConfig([
            dirname(__DIR__, 2) . '/src/Auth/Infrastructure/Doctrine/Entity',
        ], isDevMode: true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy());
        $configuration->enableNativeLazyObjects(true);
        return new EntityManager($connection, $configuration);
    }

    private function accessAdapter(): Adapter\AccessTokenRepository
    {
        $encoder = new JsonEncoder();
        $stack = new RequestStack();
        $request = new Request();
        $request->attributes->set('_dpop_jkt', $this->proofJkt);
        $stack->push($request);
        return new Adapter\AccessTokenRepository(
            new Repository\AccessTokenRepository($this->manager, $encoder),
            new Repository\ClientRepository($this->manager, $encoder),
            new UserRepository($this->manager), $stack, 'https://baander.app',
        );
    }

    private function server(string $key = 'private.pem'): AuthorizationServer
    {
        $encoder = new JsonEncoder();
        $clients = new Repository\ClientRepository($this->manager, $encoder);
        $users = new UserRepository($this->manager);
        $access = new Repository\AccessTokenRepository($this->manager, $encoder);
        $refresh = new Repository\RefreshTokenRepository($this->manager, $encoder);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->manager);
        $registry->method('resetManager')->willReturnCallback(fn (): EntityManager => $this->manager = self::manager($this->writer));
        $transaction = new DoctrineTransaction($registry, new Swoole());
        return (new AuthorizationServerFactory(
            new Adapter\ClientRepository($clients, $encoder),
            $this->accessAdapter(),
            new Adapter\ScopeRepository(new ScopeAllowlist([], [])),
            new Adapter\AuthCodeRepository(new Repository\AuthCodeRepository($this->manager, $encoder), $clients, $users),
            new Adapter\RefreshTokenRepository($refresh, $access),
            new Adapter\DeviceCodeRepository(new Repository\DeviceCodeRepository($this->manager, $encoder), $clients, $users, $encoder),
            $this->keyDirectory . '/' . $key, $this->encryptionKey, 'https://baander.app/verify',
            $transaction, $this->manager,
        ))->create();
    }

    /** @return array<string,mixed> */
    private function refresh(AuthorizationServer $server, ?string $token = null): array
    {
        $request = (new ServerRequest('POST', 'https://baander.app/api/oauth/token'))->withParsedBody([
            'grant_type' => 'refresh_token', 'client_id' => $this->clientId,
            'refresh_token' => $token ?? $this->refreshToken,
        ]);
        $request = $request->withAttribute('_dpop_jkt', $this->proofJkt);
        $response = $server->respondToAccessTokenRequest($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
