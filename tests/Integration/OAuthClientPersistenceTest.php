<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Infrastructure\Repository\OAuth\ClientRepository;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
        $this->writer->executeStatement('CREATE EXTENSION IF NOT EXISTS citext');
        $this->schema = 'oauth_client_test_' . bin2hex(random_bytes(8));
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
                // The trigram indexes are unrelated to client persistence and require pg_trgm.
                if (!str_contains($sql, 'gin_trgm_ops')) {
                    $this->writer->executeStatement($sql, $query->getParameters(), $query->getTypes());
                }
            }
        }
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
}
