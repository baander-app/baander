<?php

declare(strict_types=1);

/*
 * Child process of SuperAdminDemotionRaceTest: replaces a user's roles over its own PostgreSQL
 * session, so it can wait on the lock the test process holds while it demotes another super admin.
 *
 * Arguments: schema, application name, the user's email address and the new roles as a JSON list.
 * Prints {"roles": [...]} with the stored roles, or {"refused": reason} when the change was refused.
 */

use App\Auth\Application\Exception\LastSuperAdminException;
use App\Shared\Domain\Model\Email;
use App\Tests\Fixtures\Auth\OAuthRevocationRace;
use App\Tests\Fixtures\Auth\SuperAdminDemotionRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 5) {
    throw new InvalidArgumentException('Expected four arguments.');
}
[, $schema, $application, $email, $roles] = array_map(strval(...), $arguments);

$connection = OAuthRevocationRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");
$manager = OAuthRevocationRace::entityManager($connection);

try {
    SuperAdminDemotionRace::setRoles($manager, $email, json_decode($roles, true, flags: JSON_THROW_ON_ERROR));
    $manager->clear();
    $user = SuperAdminDemotionRace::users($manager)->findByEmail(new Email($email));
    echo json_encode(['roles' => $user?->getRoles()], JSON_THROW_ON_ERROR);
} catch (LastSuperAdminException $exception) {
    echo json_encode(['refused' => $exception->details['reason']], JSON_THROW_ON_ERROR);
}
