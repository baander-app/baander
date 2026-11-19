<?php

declare(strict_types=1);

/*
 * Child process of OAuthPushedAuthorizationRequestPersistenceTest: uses up a pushed
 * authorization request over its own PostgreSQL session, so it can wait on the row lock
 * of a decision the test process has not committed yet.
 *
 * Arguments: schema, application name, request ID and client ID. Prints the outcome as JSON.
 */

use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Infrastructure\Repository\OAuth\PushedAuthorizationRequestRepository;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Library\LibraryScanClaimRace;
use App\Tests\Integration\OAuthPushedAuthorizationRequestPersistenceTest;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 5) {
    throw new InvalidArgumentException('Expected four arguments.');
}
[, $schema, $application, $requestId, $clientId] = array_map(strval(...), $arguments);

$connection = LibraryScanClaimRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");

$consumed = new PushedAuthorizationRequestRepository(OAuthPushedAuthorizationRequestPersistenceTest::entityManager($connection))->consume(TokenId::fromString($requestId), Uuid::fromString($clientId));

echo json_encode(['consumed' => $consumed], JSON_THROW_ON_ERROR);
