<?php

declare(strict_types=1);

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Kernel;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Library\LibraryDiscoveryObservationRace;
use App\Tests\Fixtures\Library\LibraryScanClaimRace;
use Doctrine\ORM\EntityManagerInterface;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $schema, $application, $kind, $payloadFile] = $_SERVER['argv'];
$payload = json_decode((string) file_get_contents($payloadFile), true, flags: JSON_THROW_ON_ERROR);
if ($kind === 'consume') {
    $kernel = new Kernel('test', false);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $manager = $container->get('doctrine')->getManager();
    assert($manager instanceof EntityManagerInterface);
    $connection = $manager->getConnection();
    $connection->executeStatement('SET search_path TO ' . $connection->quoteIdentifier($schema) . ', public');
    $connection->executeQuery("SELECT set_config('application_name', ?, false)", [$application])->free();
    $connection->executeStatement("SET lock_timeout = '20s'");
    $message = new FilesDiscovered(Uuid::fromString($payload['library']), $payload['type'], $payload['directory'], array_map(static fn (array $file): DiscoveredFile => new DiscoveredFile(...$file), $payload['files']));
    $container->get(FilesDiscoveredHandler::class)($message);
    echo json_encode(['movies' => (int) $connection->fetchOne('SELECT count(*) FROM movies'), 'videos' => (int) $connection->fetchOne('SELECT count(*) FROM videos')], JSON_THROW_ON_ERROR);
    $kernel->shutdown();
    exit(0);
}
$connection = LibraryScanClaimRace::connect($schema, $application);
$connection->executeStatement("SET lock_timeout = '20s'");
$manager = LibraryScanClaimRace::entityManager($connection);
$index = new LibraryFileIndexRepository($manager);
$libraryId = Uuid::fromString($payload['library']);
if ($kind === 'unavailable' || $kind === 'remove') {
    // Exercise a valid heap-scan plan so reversed physical rows deterministically
    // expose unordered mutations; production prelocks must impose their own order.
    $connection->executeStatement('SET enable_indexscan = off');
    $connection->executeStatement('SET enable_bitmapscan = off');
    if ($kind === 'unavailable') {
        $index->markUnavailableByPaths($libraryId, $payload['paths']);
    } else {
        $index->removeByPaths($libraryId, $payload['paths']);
    }
    echo json_encode(['done' => true], JSON_THROW_ON_ERROR);
    exit(0);
}
$library = LibraryScanClaimRace::repository($manager)->findByUuid($libraryId);
assert($library !== null);
$transaction = new class($connection) implements TransactionPortInterface {
    public function __construct(private \Doctrine\DBAL\Connection $connection) {}
    public function run(callable $operation): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $operation());
    }
};
$published = [];
$result = LibraryDiscoveryObservationRace::scanner($connection, $library, $payload['paths'], $transaction)->scan($library, rescan: true, publishDirectory: static function (string $directory, array $files) use (&$published): void {
    $published = array_map(static fn (DiscoveredFile $file): string => $file->absolutePath, $files);
});
echo json_encode(['published' => $published, 'result' => array_map(static fn (DiscoveredFile $file): string => $file->absolutePath, array_values($result->directories)[0])], JSON_THROW_ON_ERROR);
