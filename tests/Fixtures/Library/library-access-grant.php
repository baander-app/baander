<?php

declare(strict_types=1);

/*
 * Child process of LibraryAccessTransactionTest: grants a user access to a library over its own
 * PostgreSQL session, so it can wait on a membership row the test process inserted and has not
 * committed yet.
 *
 * Arguments: schema, application name, user ID, library ID. Prints the outcome as JSON.
 */

use App\Library\Infrastructure\Doctrine\Repository\LibraryAccessRepository;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 5) {
    throw new InvalidArgumentException('Expected four arguments.');
}
[, $schema, $application, $userId, $libraryId] = array_map(strval(...), $arguments);

$url = getenv('OUTBOX_TEST_DATABASE_URL');
if ($url === false || $url === '') {
    throw new LogicException('OUTBOX_TEST_DATABASE_URL must name a disposable PostgreSQL database.');
}

CustomTypesRegistrar::register();
$dbal = new Configuration();
$dbal->setMiddlewares([new BaanderDriverMiddleware()]);
$connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url), $dbal);
$connection->executeStatement('SET search_path TO ' . $schema . ', public');
$connection->executeQuery("SELECT set_config('application_name', :name, false)", ['name' => $application])->free();
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");

$orm = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Library/Infrastructure/Doctrine/Entity'], isDevMode: true);
$orm->setNamingStrategy(new UnderscoreNamingStrategy());
$orm->enableNativeLazyObjects(true);

(new LibraryAccessRepository(new EntityManager($connection, $orm)))->grant(Uuid::fromString($userId), Uuid::fromString($libraryId));

echo json_encode(['granted' => true], JSON_THROW_ON_ERROR);
