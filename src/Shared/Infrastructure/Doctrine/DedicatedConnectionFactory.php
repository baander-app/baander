<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A DBAL connection of its own, outside DoctrineBundle's connection and its transactions,
 * with the same driver middleware: the Baander platform and a UTC session time zone.
 */
#[Exclude]
final class DedicatedConnectionFactory
{
    public static function fromUrl(#[\SensitiveParameter] string $databaseUrl): Connection
    {
        $configuration = (new Configuration())->setMiddlewares([new BaanderDriverMiddleware()]);

        return DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($databaseUrl), $configuration);
    }
}
