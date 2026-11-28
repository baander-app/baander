<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Catalog\Application\Port\PersonCreditInput;
use App\Catalog\Application\Port\PersonCreditTargetInput;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonCreditRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonIdentityRepository;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;

[, $schema, $movieId] = $_SERVER['argv'];
$connection = ArtistImportIdentityRace::connect($schema);
$transaction = new class ($connection) implements TransactionPortInterface {
    public function __construct(private readonly Connection $connection) {}

    public function run(callable $operation): mixed
    {
        return $this->connection->transactional(static fn () => $operation());
    }
};
$identities = new PersonIdentityRepository($connection, $transaction, ArtistImportIdentityRace::entityManager($connection));
$credits = new PersonCreditRepository($connection, $identities);
fwrite(STDOUT, $connection->fetchOne('SELECT pg_backend_pid()') . "\n");
fflush(STDOUT);
if (isset($_SERVER['argv'][3])) {
    $transaction->run(fn () => $credits->syncCredits([
        new PersonCreditTargetInput('movie', Uuid::fromString($movieId), [], []),
        new PersonCreditTargetInput('movie', Uuid::fromString($_SERVER['argv'][3]), [], []),
    ]));
} else {
    $transaction->run(fn () => $credits->syncMovieCredits(Uuid::fromString($movieId), [
        new PersonCreditInput(['tvdb_person' => '440', 'imdb_name' => 'nm0000044'], 'Shared Person', 'cast', 'actor', 'Actor', source: 'tvdb'),
    ], null));
}
fwrite(STDOUT, "complete\n");
