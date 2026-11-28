<?php

declare(strict_types=1);

use App\Catalog\Application\Command\Movie\DeleteMovieCommand;
use App\Catalog\Application\CommandHandler\Movie\DeleteMovieHandler;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoRepository;
use App\Catalog\Infrastructure\MovieService;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManager;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $schema, $application, $publicId] = $_SERVER['argv'];
$connection = ArtistImportIdentityRace::connect($schema, $application);
$connection->executeStatement("SET lock_timeout = '15s'");
$manager = ArtistImportIdentityRace::entityManager($connection);
$transaction = new readonly class ($manager) implements TransactionPortInterface {
    public function __construct(private EntityManager $manager) {}

    public function run(callable $operation): mixed
    {
        return $this->manager->getConnection()->transactional(function () use ($operation): mixed {
            $result = $operation();
            $this->manager->flush();

            return $result;
        });
    }
};
$handler = new DeleteMovieHandler(
    new MovieService(new MovieRepository($manager)),
    new VideoRepository($manager),
    $transaction,
);
try {
    $result = $handler(new DeleteMovieCommand($publicId));
    echo json_encode(['deleted' => $result->deleted], JSON_THROW_ON_ERROR);
} catch (DriverException $error) {
    echo json_encode(['sqlState' => $error->getSQLState(), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
