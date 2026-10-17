<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Event\MigrationsEventArgs;
use Doctrine\Migrations\Events;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Refuses to migrate a database whose history records executed migrations that the migration
 * directory does not have, such as a database migrated under former class names.
 *
 * Doctrine Migrations only asks for confirmation then, and `--no-interaction` answers yes: it would
 * run every migration it does not find in the history, including ones already applied under
 * another name. A separate, explicitly approved manual reset is required.
 */
#[AsDoctrineListener(event: Events::onMigrationsMigrating)]
final class UnknownExecutedMigrationGuard
{
    public function __construct(
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly DependencyFactory $migrations,
    ) {
    }

    public function onMigrationsMigrating(MigrationsEventArgs $event): void
    {
        $unknown = $this->migrations->getMigrationStatusCalculator()->getExecutedUnavailableMigrations();
        if (count($unknown) === 0) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Database "%s" records %d executed migrations that the migration directory does not have: %s. Migration refused. Overwriting requires explicit approval for this specific target and a separate manual reset. Do not mark the initial migration as executed.',
            (string) $event->getConnection()->fetchOne('SELECT current_database()'),
            count($unknown),
            implode(', ', array_map(static fn (ExecutedMigration $migration): string => (string) $migration->getVersion(), $unknown->getItems())),
        ));
    }
}
