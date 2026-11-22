<?php

declare(strict_types=1);

/*
 * Child process of SuperAdminRemovalRaceTest: demotes, disables or deletes a user over its own
 * PostgreSQL session, so it can wait on the lock the test process holds while it demotes another
 * super admin.
 *
 * Arguments: schema, application name, the removal (a SuperAdminRemoval value) and the user's
 * email address. Prints {"stored": {"roles": [...], "disabled": bool}} with the user as stored
 * afterwards (null once deleted), or {"refused": reason} when the removal was refused.
 */

use App\Auth\Application\Exception\LastSuperAdminException;
use App\Shared\Domain\Model\Email;
use App\Tests\Fixtures\Auth\OAuthRevocationRace;
use App\Tests\Fixtures\Auth\SuperAdminRemoval;
use App\Tests\Fixtures\Auth\SuperAdminRemovalRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 5) {
    throw new InvalidArgumentException('Expected four arguments.');
}
[, $schema, $application, $removal, $email] = array_map(strval(...), $arguments);

$connection = OAuthRevocationRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");
$manager = OAuthRevocationRace::entityManager($connection);

try {
    SuperAdminRemovalRace::remove($manager, SuperAdminRemoval::from($removal), $email);
    $manager->clear();
    $user = SuperAdminRemovalRace::users($manager)->findByEmail(new Email($email));
    echo json_encode(['stored' => $user === null ? null : ['roles' => $user->getRoles(), 'disabled' => $user->isDisabled()]], JSON_THROW_ON_ERROR);
} catch (LastSuperAdminException $exception) {
    echo json_encode(['refused' => $exception->details['reason']], JSON_THROW_ON_ERROR);
}
