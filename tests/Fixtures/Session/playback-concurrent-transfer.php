<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Session;

use App\Auth\Infrastructure\LiveUpdates\DoctrineCurrentUserAuthorityReader;
use App\Catalog\Infrastructure\Doctrine\Repository\SongRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoRepository;
use App\Catalog\Infrastructure\VideoService;
use App\Library\Application\Port\LibraryMediaFileResolverInterface;
use App\Library\Infrastructure\Doctrine\Repository\LibraryAccessRepository;
use App\Library\Infrastructure\LibraryReadScopeProvider;
use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Media\Application\Service\PlaybackMediaAccess;
use App\Media\Infrastructure\MediaReadScopeProvider;
use App\Media\Infrastructure\Service\StreamService;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Infrastructure\Doctrine\PlaybackStore;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\DedicatedConnectionFactory;
use App\Shared\Infrastructure\Doctrine\DoctrineTransaction;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

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
    private static function mediaAccess(EntityManager $manager): PlaybackMediaAccessInterface
    {
        $security = new Security(new Container());
        $libraries = new LibraryReadScopeProvider(
            $security,
            new LibraryAccessRepository($manager),
            new DoctrineCurrentUserAuthorityReader($manager->getConnection()),
        );
        $files = new class implements LibraryMediaFileResolverInterface {
            public function resolve(Uuid $libraryId, string $absolutePath): string
            {
                throw new \LogicException('Playback authorization must not probe media files.');
            }

            public function resolveInAnyLibrary(string $absolutePath): string
            {
                throw new \LogicException('Playback authorization must not probe media files.');
            }
        };
        $songs = new SongRepository(
            $manager,
            new CursorPaginator(),
            new CursorCodec(new JsonEncoder()),
        );
        return new PlaybackMediaAccess(
            new MediaReadScopeProvider($security, $libraries),
            new StreamService($songs, $files),
            new VideoService(new VideoRepository($manager), $files),
        );
    }

}

// Run as a program, this is a second process with its own connection: it performs one coordinator
// operation when its parent releases it, and answers with the snapshot or with the conflict.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        [$script, $schema, $application, $owner, $operation, $input] = $_SERVER['argv'] ?? throw new \LogicException('Requires CLI arguments.');
        $manager = PlaybackPersistenceFixture::manager(PlaybackPersistenceFixture::connect($schema, $application));
        $coordinator = PlaybackPersistenceFixture::coordinator($manager);
        fwrite(STDOUT, "ready\n");
        fflush(STDOUT);
        if (fgets(STDIN) !== "go\n") {
            throw new \RuntimeException('Missing release.');
        }
        try {
            $result = $coordinator->execute(Uuid::fromString($owner), PlaybackOperation::from($operation), json_decode($input, true, flags: JSON_THROW_ON_ERROR));
            $answer = 'committed ' . json_encode($result, JSON_THROW_ON_ERROR);
        } catch (ConflictException) {
            $answer = 'conflict';
        }
        // A refused insert closes the entity manager it was flushed through, also when the caller
        // was then answered with a conflict.
        if (!$manager->isOpen()) {
            throw new \LogicException('The operation closed its entity manager.');
        }
        fwrite(STDOUT, $answer . "\n");
    } catch (\Throwable $error) {
        fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
        exit(1);
    }
}
