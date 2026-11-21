<?php

declare(strict_types=1);

namespace App\Metadata\Application\Port;

interface PersonProfileSyncRequestInterface
{
    /** Queue pending profiles after the credit transaction commits. */
    public function request(): void;
}
