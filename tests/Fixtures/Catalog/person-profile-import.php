<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Catalog\Infrastructure\Doctrine\Repository\PersonIdentityRepository;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;

[, $schema, $tmdbId, $imdbId] = $_SERVER['argv'] ?? throw new \LogicException('Run the Person import fixture from the console.');
$connection = ArtistImportIdentityRace::connect($schema);
$transaction = new class ($connection) implements TransactionPortInterface {
    public function __construct(private readonly Connection $connection) {}

    public function run(callable $operation): mixed
    {
        return $this->connection->transactional(static fn () => $operation());
    }
};

fwrite(STDOUT, $connection->fetchOne('SELECT pg_backend_pid()') . "\n");
fflush(STDOUT);
$people = new PersonIdentityRepository($connection, $transaction, ArtistImportIdentityRace::entityManager($connection));
$people->enrichTmdbPerson($tmdbId, ['imdb_name' => $imdbId], ['biography' => 'Profile from a concurrent request']);
fwrite(STDOUT, "complete\n");
