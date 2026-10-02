<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Infrastructure\Cache\CachedAccessTokenRepository;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Auth\Infrastructure\Repository\OAuth\AccessTokenRepository;
use App\Auth\Infrastructure\Repository\OAuth\ClientRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/** Actual ORM writes and cache entries, observed outside the writer transaction. */
final class AccessTokenCacheTransactionTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManager $entityManager;
    private TagAwareAdapter $cache;
    private CachedAccessTokenRepository $repository;
    private Client $client;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $params['serverVersion'] = '18';
        $params['charset'] = 'utf8';
        $this->writer = DriverManager::getConnection($params);
        $this->observer = DriverManager::getConnection($params);
        $this->writer->executeStatement('CREATE EXTENSION IF NOT EXISTS citext');
        $this->schema = 'access_token_cache_test_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->writer, $this->observer] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        }

        CustomTypesRegistrar::register();
        $config = ORMSetup::createAttributeMetadataConfig([
            dirname(__DIR__, 2) . '/src/Auth/Infrastructure/Doctrine/Entity',
        ], isDevMode: true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->enableNativeLazyObjects(true);
        $this->entityManager = new EntityManager($this->writer, $config);
        // Preserve actual production mappings, including inherited timezone-free timestamps.
        (new SchemaTool($this->entityManager))->createSchema(array_map(
            $this->entityManager->getClassMetadata(...),
            [UserEntity::class, ClientEntity::class, AccessTokenEntity::class],
        ));

        $clientEntity = new ClientEntity(new PublicId(), 'Cache transaction test', '["https://baander.app/callback"]');
        $this->entityManager->persist($clientEntity);
        $this->entityManager->flush();
        $client = (new ClientRepository($this->entityManager, new JsonEncoder()))->findClientByUuid($clientEntity->getId());
        self::assertNotNull($client);
        $this->client = $client;
        $this->cache = new TagAwareAdapter(new ArrayAdapter());
        $this->repository = new CachedAccessTokenRepository(
            new AccessTokenRepository($this->entityManager, new JsonEncoder()),
            $this->cache,
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
        }
        if (isset($this->entityManager)) {
            $this->entityManager->clear();
        }
        if (isset($this->schema)) {
            $this->observer->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        if (isset($this->observer)) {
            $this->observer->close();
        }
        if (isset($this->writer)) {
            $this->writer->close();
        }
    }

    public function testDeferredSaveDoesNotFlushOrPublishRevocationBeforeRollback(): void
    {
        $token = $this->seedActiveToken();
        $this->setCachedStatus($token, false);
        $this->writer->beginTransaction();
        $token->revoke();
        $this->repository->save($token, flush: false);

        self::assertFalse($this->cache->getItem($this->cacheKey($token))->isHit());
        self::assertSame(0, $this->revokedStatus($this->writer, $token));
        self::assertSame(0, $this->revokedStatus($this->observer, $token));
        $this->entityManager->flush();
        self::assertSame(1, $this->revokedStatus($this->writer, $token));
        self::assertSame(0, $this->revokedStatus($this->observer, $token));

        $this->writer->rollBack();
        $this->entityManager->clear();
        self::assertSame(0, $this->revokedStatus($this->observer, $token));
        $this->setCachedStatus($token, true);
        $loaded = $this->repository->findByTokenId($token->getTokenId());
        self::assertNotNull($loaded, 'A stale revoked cache entry must not hide the rolled-back active token.');
        self::assertFalse($loaded->isRevoked());
    }

    public function testFlushedRevocationInsideOuterTransactionDoesNotSurviveRollback(): void
    {
        $token = $this->seedActiveToken();
        $this->writer->beginTransaction();
        $token->revoke();
        $this->repository->save($token);

        self::assertSame(1, $this->revokedStatus($this->writer, $token));
        self::assertSame(0, $this->revokedStatus($this->observer, $token));
        self::assertFalse($this->cache->getItem($this->cacheKey($token))->isHit());
        $this->writer->rollBack();
        $this->entityManager->clear();

        self::assertSame(0, $this->revokedStatus($this->observer, $token));
        $loaded = $this->repository->findByTokenId($token->getTokenId());
        self::assertNotNull($loaded);
        self::assertFalse($loaded->isRevoked());
    }

    public function testCommittedRevocationIsReadDespiteStaleNullOrFalseCacheEntries(): void
    {
        foreach ([null, false] as $staleStatus) {
            $token = $this->seedActiveToken();
            $this->setCachedStatus($token, $staleStatus);
            $this->writer->beginTransaction();
            $token->revoke();
            $this->repository->save($token);
            self::assertFalse($this->cache->getItem($this->cacheKey($token))->isHit());
            self::assertSame(0, $this->revokedStatus($this->observer, $token));
            $this->writer->commit();
            $this->entityManager->clear();

            self::assertSame(1, $this->revokedStatus($this->observer, $token));
            $this->setCachedStatus($token, $staleStatus);
            $loaded = $this->repository->findByTokenId($token->getTokenId());
            self::assertNotNull($loaded);
            self::assertTrue($loaded->isRevoked());
        }
    }

    private function seedActiveToken(): AccessToken
    {
        $token = AccessToken::issue($this->client);
        $this->repository->save($token);
        self::assertSame(0, $this->revokedStatus($this->observer, $token));

        return $token;
    }

    private function setCachedStatus(AccessToken $token, ?bool $status): void
    {
        $item = $this->cache->getItem($this->cacheKey($token));
        $item->set($status);
        $item->expiresAfter(60);
        self::assertTrue($this->cache->save($item));
        self::assertTrue($this->cache->getItem($this->cacheKey($token))->isHit());
        self::assertSame($status, $this->cache->getItem($this->cacheKey($token))->get());
    }

    private function cacheKey(AccessToken $token): string
    {
        return 'oauth_revoked_' . $token->getTokenId()->toString();
    }

    private function revokedStatus(Connection $connection, AccessToken $token): int
    {
        $status = $connection->fetchOne(
            'SELECT revoked::int FROM oauth_access_tokens WHERE token_id = ?',
            [$token->getTokenId()->toString()],
        );
        self::assertNotFalse($status, 'The committed seed token must exist.');

        return (int) $status;
    }
}
