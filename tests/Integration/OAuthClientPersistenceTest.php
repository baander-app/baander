<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientAuthMethod;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Doctrine\UserRepository;
use App\Auth\Infrastructure\Repository\OAuth\AccessTokenRepository;
use App\Auth\Infrastructure\Repository\OAuth\AuthCodeRepository;
use App\Auth\Infrastructure\Repository\OAuth\ClientRepository;
use App\Auth\Infrastructure\Repository\OAuth\DeviceCodeRepository;
use App\Auth\Infrastructure\Repository\OAuth\RefreshTokenRepository;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use DateInterval;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/** OAuth client persistence against the migration's oauth tables in an isolated schema. */
final class OAuthClientPersistenceTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->writer = DriverManager::getConnection($params);
        $this->observer = DriverManager::getConnection($params);
        $this->schema = 'oauth_client_test_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->writer, $this->observer] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        }
        \App\Tests\Support\FinalSchemaBootstrap::bootstrap($this->writer);
        CustomTypesRegistrar::register();
        $configuration = ORMSetup::createAttributeMetadataConfig([
            dirname(__DIR__, 2) . '/src/Auth/Infrastructure/Doctrine/Entity',
        ], isDevMode: true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy());
        $configuration->enableNativeLazyObjects(true);
        $this->manager = new EntityManager($this->writer, $configuration);
    }

    protected function tearDown(): void
    {
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
    }

    public function testFreshClientUsesInstanceOriginAndResumePreservesItsIdentity(): void
    {
        $repository = new ClientRepository($this->manager, new JsonEncoder());
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \App\Auth\Interface\Console\SetupClientsCommand($repository));
        self::assertSame(0, $tester->execute(['--origin' => 'https://dev.baander.app']));
        $client = $repository->findClientByPublicId(\App\Shared\Domain\Model\PublicId::fromString(\App\Auth\Interface\Console\SetupClientsCommand::SPA_PUBLIC_ID));
        self::assertNotNull($client);
        $id = $client->getId()->toString();
        self::assertSame(['https://dev.baander.app'], $client->getRedirectUris());
        self::assertSame(0, $tester->execute(['--origin' => 'https://changed.baander.app']));
        $this->manager->clear();
        $retained = $repository->findClientByPublicId($client->getPublicId());
        self::assertNotNull($retained);
        self::assertSame($id, $retained->getId()->toString());
        self::assertSame(['https://dev.baander.app'], $retained->getRedirectUris());
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_clients'));
    }

    public function testClientSaveLoadAndResavePreservePersistentUuid(): void
    {
        $repository = new ClientRepository($this->manager, new JsonEncoder());
        $client = Client::create('Persistent client', ['https://baander.app/callback']);
        $repository->saveClient($client);
        $this->manager->clear();
        $loaded = $repository->findClientByPublicId($client->getPublicId());
        self::assertNotNull($loaded);
        self::assertSame($client->getId()->toString(), $loaded->getId()->toString());
        $repository->saveClient($loaded);
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM oauth_clients WHERE public_id = ?', [$client->getPublicId()->toString()]));
    }

    public function testAKeyClientKeepsItsMethodAndKeysInEveryRepositoryThatRebuildsIt(): void
    {
        $repository = new ClientRepository($this->manager, new JsonEncoder());
        $jwks = self::jwks();
        $client = Client::registerConfidentialWithKeys('Key client', ['https://server.baander.app/callback'], ClientKeySource::fromJwks($jwks));
        $repository->saveClient($client);
        $rows = $this->insertGrantRows($client->getId());
        $this->manager->clear();

        $encoder = new JsonEncoder();
        $rebuilt = [
            'client' => $repository->findClientByUuid($client->getId()),
            'auth code' => new AuthCodeRepository($this->manager, $encoder)->findByCodeId(TokenId::fromString($rows['auth code']))?->getClient(),
            'device code' => new DeviceCodeRepository($this->manager, $encoder)->findById(Uuid::fromString($rows['device code']))?->getClient(),
            'access token' => new AccessTokenRepository($this->manager, $encoder)->findByTokenId(TokenId::fromString($rows['access token']))?->getClient(),
            'refresh token' => new RefreshTokenRepository($this->manager, $encoder)->findByTokenId(TokenId::fromString($rows['refresh token']))?->getAccessToken()->getClient(),
        ];

        foreach ($rebuilt as $path => $loaded) {
            self::assertNotNull($loaded, $path);
            self::assertSame(ClientAuthMethod::PrivateKeyJwt, $loaded->getAuthMethod(), $path);
            self::assertNull($loaded->getSecretHash(), $path);
            // jsonb stores object members in its own order, so the set is compared by value.
            self::assertEquals($jwks, $loaded->getKeySource()?->jwks(), $path);
            self::assertNull($loaded->getKeySource()?->jwksUri(), $path);
        }
    }

    public function testKeysReplacedAfterAReloadAreStoredInPlaceOfTheFormerSource(): void
    {
        $repository = new ClientRepository($this->manager, new JsonEncoder());
        $client = Client::registerConfidentialWithKeys('Key client', ['https://server.baander.app/callback'], ClientKeySource::fromJwks(self::jwks()));
        $repository->saveClient($client);
        $this->manager->clear();

        $loaded = $repository->findClientByUuid($client->getId());
        self::assertNotNull($loaded);
        $loaded->replaceKeys(ClientKeySource::fromJwksUri('https://server.baander.app/jwks.json'));
        $repository->saveClient($loaded);
        $this->manager->clear();

        $reloaded = $repository->findClientByUuid($client->getId());
        self::assertNotNull($reloaded);
        self::assertSame('https://server.baander.app/jwks.json', $reloaded->getKeySource()?->jwksUri());
        self::assertSame(
            ['token_endpoint_auth_method' => 'private_key_jwt', 'jwks' => null, 'jwks_uri' => 'https://server.baander.app/jwks.json'],
            $this->observer->fetchAssociative('SELECT token_endpoint_auth_method, jwks, jwks_uri FROM oauth_clients WHERE id = ?', [$client->getId()->toString()]),
        );
    }

    public function testRegisteredClientsStoreTheMethodTheyAuthenticateWith(): void
    {
        $repository = new ClientRepository($this->manager, new JsonEncoder());
        $clients = [
            'client_secret_post' => Client::registerConfidential('Secret client', ['https://server.baander.app/callback'], ClientSecret::generate()),
            'none' => Client::registerPublic('Public client', ['https://player.baander.app/callback']),
        ];
        foreach ($clients as $client) {
            $repository->saveClient($client);
        }
        $this->manager->clear();

        foreach ($clients as $method => $client) {
            self::assertSame($method, $this->observer->fetchOne('SELECT token_endpoint_auth_method FROM oauth_clients WHERE id = ?', [$client->getId()->toString()]));
            self::assertSame(ClientAuthMethod::from($method), $repository->findClientByUuid($client->getId())?->getAuthMethod());
        }
    }

    /**
     * A code, a device code and a token pair issued to the client, as rows.
     *
     * @return array{'auth code': string, 'device code': string, 'access token': string, 'refresh token': string}
     */
    private function insertGrantRows(Uuid $client): array
    {
        $times = ['created_at' => '2026-11-19 12:00:00+00', 'updated_at' => '2026-11-19 12:00:00+00'];
        $user = Uuid::generate()->toString();
        $access = Uuid::generate()->toString();
        $deviceCode = Uuid::generate()->toString();
        $tokens = [
            'auth code' => str_repeat('c', 40),
            'access token' => str_repeat('a', 40),
            'refresh token' => str_repeat('r', 40),
        ];
        $this->writer->insert('users', ['id' => $user, 'public_id' => (new \App\Shared\Domain\Model\PublicId())->toString(),
            'name' => 'Key client user', 'email' => 'key-client@baander.app', 'password' => 'test-only', ...$times]);
        $this->writer->insert('oauth_auth_codes', ['id' => Uuid::generate()->toString(), 'code_id' => $tokens['auth code'],
            'client_id' => $client->toString(), 'user_id' => $user, 'redirect_uri' => 'https://server.baander.app/callback',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'code_challenge_method' => 'S256',
            'dpop_jkt' => '0ZcOCORZNYy-DWpqq30jZyJGHTN0d2HglBV3uiguA4I', ...$times]);
        $this->writer->insert('oauth_device_codes', ['id' => $deviceCode, 'device_code' => bin2hex(random_bytes(16)),
            'user_code' => strtoupper(bin2hex(random_bytes(6))), 'client_id' => $client->toString(),
            'verification_uri' => 'https://app.baander.app/device', 'expires_at' => '2026-11-19 12:15:00+00', ...$times]);
        $this->writer->insert('oauth_access_tokens', ['id' => $access, 'token_id' => $tokens['access token'],
            'client_id' => $client->toString(), 'user_id' => $user, ...$times]);
        $this->writer->insert('oauth_refresh_tokens', ['id' => Uuid::generate()->toString(), 'token_id' => $tokens['refresh token'],
            'access_token_id' => $access, ...$times]);

        return [...$tokens, 'device code' => $deviceCode];
    }

    /** @return array{keys: list<array<string, string>>} */
    private static function jwks(): array
    {
        return ['keys' => [
            ['kty' => 'EC', 'crv' => 'P-256', 'kid' => 'signing-1', 'x' => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU', 'y' => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0'],
            ['kty' => 'RSA', 'kid' => 'signing-2', 'e' => 'AQAB', 'n' => str_repeat('u1SU1LfVLPHCozMxH2Mo', 17)],
        ]];
    }

    /** Two users who both saw the code pending approve it: one approval is recorded, for its user alone. */
    public function testOfTwoApprovalsOfOnePendingDeviceCodeOnlyTheFirstIsRecorded(): void
    {
        $users = new UserRepository(
            $this->manager,
            $this->createStub(PasswordResetTokenRepositoryInterface::class),
            $this->createStub(EmailVerificationTokenRepositoryInterface::class),
        );
        $first = User::register(new Email('first-' . bin2hex(random_bytes(4)) . '@baander.app'), 'hashed', 'First');
        $second = User::register(new Email('second-' . bin2hex(random_bytes(4)) . '@baander.app'), 'hashed', 'Second');
        $users->save($first);
        $users->save($second);
        $client = Client::create('Living room TV', [], deviceClient: true);
        new ClientRepository($this->manager, new JsonEncoder())->saveClient($client);
        $codes = new DeviceCodeRepository($this->manager, new JsonEncoder());
        $code = DeviceCode::create($client, 'BCDF-GHJK', 'https://baander.app/device', ttl: new DateInterval('PT15M'));
        $codes->save($code);
        $this->manager->clear();

        // Both approvals read the code as pending before either writes.
        $seenByFirst = $codes->findByUserCode('BCDF-GHJK');
        $seenBySecond = $codes->findByUserCode('BCDF-GHJK');
        self::assertNotNull($seenByFirst);
        self::assertNotNull($seenBySecond);
        self::assertTrue($seenByFirst->isPending() && $seenBySecond->isPending());
        $seenByFirst->approve($first);
        $seenBySecond->approve($second);

        self::assertTrue($codes->approve($seenByFirst));
        self::assertFalse($codes->approve($seenBySecond));
        self::assertSame(
            ['approved' => true, 'user_id' => $first->getId()->toString()],
            $this->observer->fetchAssociative('SELECT approved, user_id FROM oauth_device_codes WHERE id = ?', [$code->getId()->toString()]),
        );
    }
}
