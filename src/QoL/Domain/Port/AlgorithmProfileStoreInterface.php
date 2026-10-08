<?php

declare(strict_types=1);

namespace App\QoL\Domain\Port;

use App\QoL\Domain\ValueObject\AlgorithmProfile;

/**
 * Holds the governor's algorithm profile. In the web server it is one value every
 * HTTP worker reads, so a worker cannot keep a profile the others no longer use.
 */
interface AlgorithmProfileStoreInterface
{
    public function get(): AlgorithmProfile;

    public function set(AlgorithmProfile $profile): void;
}
