<?php

declare(strict_types=1);

/*
 * Child process of OAuthClientRevocationRaceTest: issues a token pair or revokes the client
 * over its own PostgreSQL session, so it can wait on a row lock the test process holds.
 *
 * Arguments: schema, application name, operation (issue|revoke), client ID, client public ID,
 * user ID, user public ID. Prints the outcome as JSON.
 */

use App\Auth\Application\Exception\OAuthProtocolException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\OAuthRevocationRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 8) {
    throw new InvalidArgumentException('Expected seven arguments.');
}
[, $schema, $application, $operation, $clientId, $clientPublicId, $userId, $userPublicId] = array_map(strval(...), $arguments);

$connection = OAuthRevocationRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");
$manager = OAuthRevocationRace::entityManager($connection);

if ($operation === 'issue') {
    try {
        $tokens = OAuthRevocationRace::issue(
            $manager,
            Uuid::fromString($clientId),
            OAuthRevocationRace::user(Uuid::fromString($userId), PublicId::fromString($userPublicId)),
        );
        echo json_encode(['issued' => $tokens->getRefreshToken()], JSON_THROW_ON_ERROR);
    } catch (OAuthProtocolException $exception) {
        echo json_encode(['error' => $exception->error, 'status' => $exception->statusCode], JSON_THROW_ON_ERROR);
    }
} elseif ($operation === 'revoke') {
    OAuthRevocationRace::revoke($manager, Uuid::fromString($userId), PublicId::fromString($clientPublicId));
    echo json_encode(['revoked' => true], JSON_THROW_ON_ERROR);
} else {
    throw new InvalidArgumentException('Unknown operation ' . $operation);
}
