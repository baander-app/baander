<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\LoginBlock;

/** Removes one login block: DELETE /api/admin/login-blocks/{id} and `app:login-block:delete <id>`. */
final readonly class DeleteLoginBlockCommand
{
    public function __construct(
        /** The block's UUID as the caller gave it; a malformed one names no block. */
        public string $id,
    ) {
    }
}
