<?php

declare(strict_types=1);

use App\Catalog\Infrastructure\Doctrine\Repository\VideoMatchStateRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoProviderPauseRepository;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $schema, $application, $action, $json] = $_SERVER['argv'];
$payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
$connection = ArtistImportIdentityRace::connect($schema, $application);
$connection->executeStatement("SET lock_timeout = '15s'");
$states = new VideoMatchStateRepository($connection);
$now = new DateTimeImmutable();
$result = match ($action) {
    'claim' => $states->claimOldestDueRequest($now, Uuid::fromString($payload['claim']), 900),
    'complete' => $states->completeClaim(Uuid::fromString($payload['id']), Uuid::fromString($payload['claim']), $now),
    'request' => $states->requestMovie(Uuid::fromString($payload['movie']), 'admin', $now),
    'pause' => (new VideoProviderPauseRepository($connection))->pause($payload['provider'], 'unavailable'),
    default => throw new LogicException('Unknown video queue contender action.'),
};
echo json_encode(['result' => $result], JSON_THROW_ON_ERROR);
