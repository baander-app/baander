<?php

declare(strict_types=1);

use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Metadata\MovieMetadataRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $schema, $application, $movieId, $names] = $_SERVER['argv'];
$connection = ArtistImportIdentityRace::connect($schema, $application);
$connection->executeStatement("SET lock_timeout = '10s'");
$result = MovieMetadataRace::apply(
    ArtistImportIdentityRace::entityManager($connection),
    Uuid::fromString($movieId),
    json_decode($names, true, flags: JSON_THROW_ON_ERROR),
);
echo json_encode(['success' => $result], JSON_THROW_ON_ERROR);
