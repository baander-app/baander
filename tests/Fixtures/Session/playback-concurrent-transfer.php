<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Session;

use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Infrastructure\Doctrine\PlaybackStore;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\DedicatedConnectionFactory;
use App\Shared\Infrastructure\Doctrine\DoctrineTransaction;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

final class PlaybackPersistenceFixture
{
    public static function connect(string $schema, ?string $application = null): Connection
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            throw new \LogicException('Requires disposable PostgreSQL.');
        }
        $connection = DedicatedConnectionFactory::fromUrl($url);
        $connection->executeStatement('SET search_path TO ' . $connection->quoteIdentifier($schema) . ', public');
        $connection->executeStatement("SET lock_timeout = '10s'");
        if ($application !== null) {
            $connection->executeQuery("SELECT set_config('application_name',?,false)", [$application])->free();
        }
        return $connection;
    }

    public static function manager(Connection $connection): EntityManager
    {
        CustomTypesRegistrar::register();
        $paths = glob(dirname(__DIR__, 3) . '/src/*/Infrastructure/Doctrine/Entity');
        if ($paths === false) {
            throw new \LogicException('Fixture metadata paths are unavailable.');
        }
        $config = ORMSetup::createAttributeMetadataConfig($paths, isDevMode: true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->enableNativeLazyObjects(true);
        return new EntityManager($connection, $config);
    }

    public static function coordinator(EntityManager $manager): PlaybackCoordinator
    {
        $registry = new class ($manager) extends AbstractManagerRegistry {
            public function __construct(private EntityManager $manager)
            {
                parent::__construct('playback_fixture', ['default' => 'connection'], ['default' => 'manager'], 'default', 'default');
            }
            protected function getService(string $name): object
            {
                return $name === 'connection' ? $this->manager->getConnection() : $this->manager;
            }
            protected function resetService(string $name): void
            {
                $this->manager = PlaybackPersistenceFixture::manager($this->manager->getConnection());
            }
        };
        // In the application the store holds a pooled proxy that always resolves the current
        // entity manager. The fixture's store resolves the registry's manager on every call for the
        // same reason: a failed flush closes a manager, the transaction port resets the registry, and
        // a store holding the closed manager could serve nothing afterwards.
        $current = new class ($registry) extends EntityManagerDecorator {
            /** @var EntityManagerInterface */
            protected ObjectManager $wrapped {
                get {
                    $manager = $this->registry->getManager();
                    if (!$manager instanceof EntityManagerInterface) {
                        throw new \LogicException('The fixture registry holds no entity manager.');
                    }
                    $this->wrapped = $manager;
                    return $this->wrapped;
                }
            }

            public function __construct(private readonly AbstractManagerRegistry $registry) {}
        };
        return new PlaybackCoordinator(new PlaybackStore($current), new DoctrineTransaction($registry, new Swoole()), self::mediaAccess($manager));
    }
    private static function mediaAccess(EntityManager $manager): \App\Media\Application\Port\PlaybackMediaAccessInterface
    {
        $security = new \Symfony\Bundle\SecurityBundle\Security(new \Symfony\Component\DependencyInjection\Container());
        $libraries = new \App\Library\Infrastructure\LibraryReadScopeProvider(
            $security,
            new \App\Library\Infrastructure\Doctrine\Repository\LibraryAccessRepository($manager),
            new \App\Auth\Infrastructure\LiveUpdates\DoctrineCurrentUserAuthorityReader($manager->getConnection()),
        );
        $files = new class implements \App\Library\Application\Port\LibraryMediaFileResolverInterface {
            public function resolve(Uuid $libraryId, string $absolutePath): string
            {
                throw new \LogicException('Playback authorization must not probe media files.');
            }
        };
        $songs = new \App\Catalog\Infrastructure\Doctrine\Repository\SongRepository(
            $manager,
            new \App\Shared\Infrastructure\Pagination\CursorPaginator(),
            new \App\Shared\Infrastructure\Pagination\CursorCodec(new \Symfony\Component\Serializer\Encoder\JsonEncoder()),
        );
        return new \App\Media\Application\Service\PlaybackMediaAccess(
            new \App\Media\Infrastructure\MediaReadScopeProvider($security, $libraries),
            new \App\Media\Infrastructure\Service\StreamService($songs, $files),
            new \App\Catalog\Infrastructure\VideoService(new \App\Catalog\Infrastructure\Doctrine\Repository\VideoRepository($manager), $files),
        );
    }

}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        [$script,$schema,$application,$owner,$target,$revision] = $_SERVER['argv'] ?? throw new \LogicException('Requires CLI arguments.');
        $manager = PlaybackPersistenceFixture::manager(PlaybackPersistenceFixture::connect($schema, $application));
        $coordinator = PlaybackPersistenceFixture::coordinator($manager);
        fwrite(STDOUT, "ready\n");
        fflush(STDOUT);
        if (fgets(STDIN) !== "go\n") {
            throw new \RuntimeException('Missing transfer release.');
        }
        try {
            $coordinator->execute(Uuid::fromString($owner), PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => (int) $revision, 'targetIncarnationId' => $target]);
            fwrite(STDOUT, "committed\n");
        } catch (\App\Shared\Application\Exception\ConflictException) {
            fwrite(STDOUT, "conflict\n");
        }
    } catch (\Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
