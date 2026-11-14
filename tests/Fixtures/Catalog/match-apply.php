<?php

declare(strict_types=1);

/*
 * Child process of MusicBrainzMatchApplyRaceTest and MatchedSongRetagRaceTest: a second PostgreSQL
 * session that can wait on the locks the test process holds.
 *
 * Arguments: schema, application name, mode and a JSON payload.
 * - "apply" {"tag", "albumPublicId", "selection"}: applies the release to the album with the
 *   selection, as an admin does, and prints {"outcome", "merged", "removed"}, or {"conflict": reason}.
 * - "import" {"name", "songId"}: resolves an artist by its name alone and credits it as primary on the
 *   song in one transaction, as an import does, and prints {"artist": ID}, or {"sqlstate": "..."}.
 * - "retag" {"artists": [[tagged name, MusicBrainz IDs], ...], "songId"}: takes a retag's locks of the
 *   song and the artists its tags name, reads the song as the retag reads it then, commits and prints
 *   {"matchedFields": [...], "credits": [[artist name, role, credited name], ...]}.
 */

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\MatchApplyRace;
use App\Tests\Fixtures\Catalog\MatchSelections;
use App\Tests\Fixtures\Catalog\RetagLockRace;
use Doctrine\DBAL\Exception\DriverException;

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

if ($mode === 'apply') {
    $manager = ArtistImportIdentityRace::entityManager($connection);
    $handler = MatchApplyRace::handler($manager, MatchApplyRace::lookup($payload['tag']));
    try {
        $result = ApplyMatchCommand::manual(
            MatchItemType::Album,
            $payload['albumPublicId'],
            MatchApplyRace::RELEASE_ID,
            MatchSelections::fromArray($payload['selection']),
        ) |> $handler(...);
        echo json_encode(['outcome' => $result->outcome->value, 'merged' => $result->mergedArtists, 'removed' => $result->removedArtists], JSON_THROW_ON_ERROR);
    } catch (ConflictException $conflict) {
        echo json_encode(['conflict' => $conflict->details['reason'] ?? null], JSON_THROW_ON_ERROR);
    }

    return;
}

if ($mode === 'import') {
    $manager = ArtistImportIdentityRace::entityManager($connection);
    $connection->beginTransaction();
    try {
        $identity = ArtistImportIdentityRace::identities([[$payload['name'], []]])[0];
        $artistId = ArtistImportIdentityRace::resolver($manager)->resolve([$identity])[$identity->key()];
        $connection->executeStatement(
            "INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (:id, :artistId, :songId, 'primary', :name)",
            ['id' => (new Uuid())->toString(), 'artistId' => $artistId->toString(), 'songId' => $payload['songId'], 'name' => $payload['name']],
        );
        $connection->commit();
        echo json_encode(['artist' => $artistId->toString()], JSON_THROW_ON_ERROR);
    } catch (DriverException $exception) {
        $connection->rollBack();
        echo json_encode(['sqlstate' => $exception->getSQLState()], JSON_THROW_ON_ERROR);
    }

    return;
}

$connection->beginTransaction();
$songs = RetagLockRace::songs($connection);
$songs->lockForRetag(ArtistImportIdentityRace::identities($payload['artists']), [], [new Uuid($payload['songId'])]);
$song = $songs->findFreshByUuid(new Uuid($payload['songId'])) ?? throw new RuntimeException('The song is gone.');
$credits = MatchApplyRace::songCredits($connection, $payload['songId']);
$connection->commit();

echo json_encode([
    'matchedFields' => array_column($song->getMatchedFields(), 'value'),
    'credits' => $credits,
], JSON_THROW_ON_ERROR);
