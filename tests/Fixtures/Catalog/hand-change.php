<?php

declare(strict_types=1);

/*
 * Child process of HandChangeRaceTest: a second PostgreSQL session that can wait on the locks the
 * test process holds.
 *
 * Arguments: schema, application name, mode and a JSON payload.
 * - "add", "remove", "role" and "edit": makes the hand change HandChangeRace::change() describes and
 *   prints what it returns: {"outcome": "changed"}, {"notFound": message} or
 *   {"conflict": reason, "deadlock": bool}.
 * - "import" {"artists": [[tagged name, MusicBrainz IDs], ...], "songId"}: takes a retag's locks of
 *   the song and the artists its tags name, resolves those artists as an import does, which gives a
 *   same-name artist without an ID the tagged one, commits and prints {"artists": [ID, ...]}.
 */

use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\HandChangeRace;
use App\Tests\Fixtures\Catalog\RetagLockRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 5) {
    throw new InvalidArgumentException('Expected four arguments.');
}
[, $schema, $application, $mode, $payload] = array_map(strval(...), $arguments);
$payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

$connection = ArtistImportIdentityRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");
$manager = ArtistImportIdentityRace::entityManager($connection);

if ($mode !== 'import') {
    echo json_encode(HandChangeRace::change($manager, $mode, $payload), JSON_THROW_ON_ERROR);

    return;
}

$identities = ArtistImportIdentityRace::identities($payload['artists']);
$connection->beginTransaction();
RetagLockRace::songs($connection)->lockForRetag($identities, [], [new Uuid($payload['songId'])]);
$artists = ArtistImportIdentityRace::resolver($manager)->resolve($identities);
$connection->commit();

echo json_encode(['artists' => array_map(static fn (Uuid $id): string => $id->toString(), $artists) |> array_values(...)], JSON_THROW_ON_ERROR);
