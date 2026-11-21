<?php

declare(strict_types=1);

use App\Catalog\Domain\ValueObject\DirectorCredit;
use App\Catalog\Infrastructure\Doctrine\Repository\DirectorRepository;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $schema, $application, $movieId, $tmdbId] = $_SERVER['argv'];
$connection = ArtistImportIdentityRace::connect($schema, $application);
$connection->executeStatement("SET lock_timeout = '10s'");
$manager = ArtistImportIdentityRace::entityManager($connection);
$connection->transactional(static function () use ($manager, $movieId, $tmdbId): void {
    new DirectorRepository($manager)->syncMovieDirectors(
        Uuid::fromString($movieId),
        [new DirectorCredit((int) $tmdbId, 'Concurrent Director')],
        true,
    );
});
echo json_encode(['people' => (int) $connection->fetchOne('SELECT COUNT(*) FROM movie_directors')], JSON_THROW_ON_ERROR);
