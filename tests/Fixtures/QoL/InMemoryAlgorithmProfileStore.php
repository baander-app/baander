<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\QoL;

use App\QoL\Domain\Port\AlgorithmProfileStoreInterface;
use App\QoL\Domain\ValueObject\AlgorithmProfile;

/** A profile held in memory, for tests that build a StreamGovernor directly. */
final class InMemoryAlgorithmProfileStore implements AlgorithmProfileStoreInterface
{
    public function __construct(
        private AlgorithmProfile $profile = AlgorithmProfile::Balanced,
    ) {
    }

    public function get(): AlgorithmProfile
    {
        return $this->profile;
    }

    public function set(AlgorithmProfile $profile): void
    {
        $this->profile = $profile;
    }
}
