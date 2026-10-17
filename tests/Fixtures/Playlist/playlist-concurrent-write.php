<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Playlist;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Catalog\Application\Port\SongLyricSignature;
use App\Playlist\Infrastructure\Doctrine\Repository\PlaylistRepository;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Infrastructure\Doctrine\DoctrineTransaction;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\AbstractManagerRegistry;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

/** Independent sessions share configuration, not an ORM identity map. */
final class PlaylistWriteRace
{
    public static function connect(string $schema, ?string $application = null): Connection
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            throw new \LogicException('OUTBOX_TEST_DATABASE_URL must name a disposable PostgreSQL database.');
        }
        $connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $connection->executeStatement('SET search_path TO ' . $connection->quoteIdentifier($schema) . ', public');
        $connection->executeStatement("SET lock_timeout = '10s'");
        if ($application !== null) {
            $connection->executeQuery("SELECT set_config('application_name', ?, false)", [$application])->free();
        }

        return $connection;
    }

    public static function manager(Connection $connection): EntityManager
    {
        CustomTypesRegistrar::register();
        $paths = glob(dirname(__DIR__, 3) . '/src/*/Infrastructure/Doctrine/Entity');
        if ($paths === false) {
            throw new \LogicException('Doctrine fixture metadata paths could not be read.');
        }
        $configuration = ORMSetup::createAttributeMetadataConfig($paths, isDevMode: true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy());
        $configuration->enableNativeLazyObjects(true);

        return new EntityManager($connection, $configuration);
    }

    public static function repository(EntityManager $manager): PlaylistRepository
    {
        $registry = new class ($manager) extends AbstractManagerRegistry {
            public function __construct(private EntityManager $manager)
            {
                parent::__construct('playlist_fixture', ['default' => 'connection'], ['default' => 'manager'], 'default', 'default');
            }

            protected function getService(string $name): object
            {
                return $name === 'connection' ? $this->manager->getConnection() : $this->manager;
            }

            protected function resetService(string $name): void
            {
                $this->manager = PlaylistWriteRace::manager($this->manager->getConnection());
            }
        };
        $songs = new class implements SongLookupInterface {
            public function findVisibleSongId(PublicId $publicId, LibraryReadScope $scope): ?Uuid
            {
                throw new \LogicException('Song visibility lookup is outside this persistence fixture.');
            }

            public function findLyricSignature(Uuid $songId): ?SongLyricSignature
            {
                throw new \LogicException('Lyrics lookup is outside this persistence fixture.');
            }

            public function songIdsAfter(?Uuid $after, int $limit): array
            {
                throw new \LogicException('Catalog paging is outside this persistence fixture.');
            }

            public function visibleSongIds(array $songIds, LibraryReadScope $scope): array
            {
                throw new \LogicException('Song visibility lookup is outside this persistence fixture.');
            }
        };

        return new PlaylistRepository($manager, $songs, new DoctrineTransaction($registry, new Swoole()));
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    [, $schema, $application, $playlistId, $action, $target] = array_map(strval(...), $_SERVER['argv']);
    $connection = PlaylistWriteRace::connect($schema, $application);
    $manager = PlaylistWriteRace::manager($connection);
    $repository = PlaylistWriteRace::repository($manager);
    $playlist = $repository->findWithSongs(Uuid::fromString($playlistId));
    if ($playlist === null) {
        throw new \LogicException('Race playlist must exist before the child starts.');
    }
    fwrite(STDOUT, "ready\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'go') {
        throw new \LogicException('The parent must release the child explicitly.');
    }
    try {
        match ($action) {
            'delete' => $repository->delete($playlist),
            'cascade-song' => $connection->executeStatement('DELETE FROM songs WHERE id = ?', [$target]),
            'cascade-user' => $connection->executeStatement('DELETE FROM users WHERE id = ?', [$target]),
            default => (static function () use ($playlist, $repository, $action, $target): void {
                if ($action === 'collaborator') {
                    $playlist->addCollaborator(Uuid::fromString($target));
                } elseif ($action === 'reorder') {
                    $playlist->reorderSongs(array_map(Uuid::fromString(...), explode(',', $target)));
                } else {
                    $playlist->updateMetadata('Child', null, false, true);
                }
                $repository->save($playlist);
            })(),
        };
        $result = ['outcome' => 'saved'];
    } catch (ConflictException $error) {
        $result = ['outcome' => 'conflict', 'exception' => $error::class];
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
}
