<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

interface OAuthSecretBundleInterface
{
    /** Prepare a new private bundle without replacing active files or changing token data. */
    public function prepare(string $directory, int $keySize): void;

    /** Require a complete, private bundle whose keys, hashes and configuration agree. */
    public function validate(string $directory): void;
}
