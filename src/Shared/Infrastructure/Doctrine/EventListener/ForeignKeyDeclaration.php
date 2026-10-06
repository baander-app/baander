<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\EventListener;

/**
 * A migration-defined foreign key whose referencing column is mapped as a scalar.
 *
 * The name is the physical constraint name; migrations did not name them uniformly.
 */
final readonly class ForeignKeyDeclaration
{
    public function __construct(
        public string $name,
        public string $table,
        public string $column,
        public string $foreignTable,
        public string $foreignColumn,
        public string $onDelete,
    ) {
    }
}
