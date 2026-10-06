<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\EventListener;

/** Implemented in the Infrastructure of the context that owns the referencing tables. */
interface ForeignKeyDeclarationProviderInterface
{
    /** @return iterable<ForeignKeyDeclaration> */
    public function foreignKeys(): iterable;
}
