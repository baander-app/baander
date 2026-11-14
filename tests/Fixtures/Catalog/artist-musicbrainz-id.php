<?php

declare(strict_types=1);

/*
 * Child process of ArtistImportIdentityRaceTest: a metadata search giving an artist a MusicBrainz
 * ID over its own PostgreSQL session, so it can wait on the import identity locks the test process
 * holds.
 *
 * Arguments: schema, application name and a JSON payload [artist ID, searched MusicBrainz ID,
 * name of the search hit]. It assigns the ID in one transaction and prints
 * {"assignment": the outcome's value}.
 */

use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ArtistMusicbrainzIdAssigner;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 4) {
    throw new InvalidArgumentException('Expected three arguments.');
}
[, $schema, $application, $payload] = array_map(strval(...), $arguments);
[$artistId, $musicbrainzId, $matchedName] = array_map(strval(...), json_decode($payload, true, flags: JSON_THROW_ON_ERROR));
$id = MusicbrainzId::fromString($musicbrainzId) ?? throw new InvalidArgumentException('Expected a MusicBrainz ID.');

$connection = ArtistImportIdentityRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");

$connection->beginTransaction();
$assignment = new ArtistMusicbrainzIdAssigner(ArtistImportIdentityRace::entityManager($connection))
    ->assign(Uuid::fromString($artistId), $id, $matchedName);
$connection->commit();

echo json_encode(['assignment' => $assignment->value], JSON_THROW_ON_ERROR);
