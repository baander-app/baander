<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use Doctrine\DBAL\Types\Type;

/**
 * Registers custom DBAL types into the global {@see Type} registry.
 *
 * Why this exists: the Swoole bundle's {@see StatefulServicesPass} re-proxifies
 * the `doctrine.dbal.default_connection` service to instantiate
 * `Doctrine\DBAL\Connection` directly via its own Instantiator, bypassing
 * DoctrineBundle's {@see \Doctrine\Bundle\DoctrineBundle\ConnectionFactory}.
 * As a result {@see ConnectionFactory::initializeTypes()} never runs, so the
 * `dbal.types` block in `config/packages/doctrine.yaml` is silently ignored
 * and every entity referencing `uuid`/`public_id`/`citext`/`job_status` fails
 * schema validation / migration diffing with "non-existent type".
 *
 * Registering the types at kernel boot (before any EntityManager is built)
 * restores them for both the HTTP worker and every console command
 * (doctrine:schema:validate, doctrine:migrations:diff, etc.).
 *
 * Keep this list in sync with `config/packages/doctrine.yaml` under
 * `doctrine.dbal.types`. Entries are idempotent — safe to call on every boot
 * and across forked workers.
 */
final class CustomTypesRegistrar
{
    /** @var array<string, class-string<Type>> */
    private const TYPES = [
        CitextType::NAME => CitextType::class,
        JobStatusType::NAME => JobStatusType::class,
        PublicIdType::NAME => PublicIdType::class,
        UuidType::NAME => UuidType::class,
    ];

    public static function register(): void
    {
        foreach (self::TYPES as $name => $class) {
            if (Type::hasType($name)) {
                Type::overrideType($name, $class);

                continue;
            }

            Type::addType($name, $class);
        }
    }
}
