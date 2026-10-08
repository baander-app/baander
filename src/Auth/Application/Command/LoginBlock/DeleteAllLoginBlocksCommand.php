<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\LoginBlock;

/** Removes every login block: DELETE /api/admin/login-blocks and `app:login-block:delete --all`. */
final readonly class DeleteAllLoginBlocksCommand
{
}
