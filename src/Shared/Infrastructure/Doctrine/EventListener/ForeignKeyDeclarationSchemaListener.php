<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\EventListener;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Keeps cross-context referential integrity visible to schema comparison without ORM associations.
 *
 * Without these declarations SchemaTool would plan to drop each constraint and the
 * implicit index DBAL derives for it from the introspected catalog.
 */
final class ForeignKeyDeclarationSchemaListener
{
    /** @param iterable<ForeignKeyDeclarationProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
    ) {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        foreach ($this->providers as $provider) {
            foreach ($provider->foreignKeys() as $foreignKey) {
                if (!$schema->hasTable($foreignKey->table)) {
                    continue;
                }

                // The referenced table can be external to a focused SchemaTool fixture.
                $schema->getTable($foreignKey->table)->addForeignKeyConstraint(
                    $foreignKey->foreignTable,
                    [$foreignKey->column],
                    [$foreignKey->foreignColumn],
                    ['onDelete' => $foreignKey->onDelete],
                    $foreignKey->name,
                );
            }
        }
    }
}
