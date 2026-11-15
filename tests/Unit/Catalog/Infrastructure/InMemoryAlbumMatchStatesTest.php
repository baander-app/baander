<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\InMemoryAlbumMatchStates;
use App\Tests\Support\Catalog\AlbumMatchStatesContractCases;
use DateTimeImmutable;
use Symfony\Component\Clock\MockClock;

/**
 * The in-memory album match states pass the cases the PostgreSQL statements pass. Their clock
 * stands still until a case lets the claims lapse, which moves it on by the lease.
 */
final class InMemoryAlbumMatchStatesTest extends AlbumMatchStatesContractCases
{
    private MockClock $clock;
    private InMemoryAlbumMatchStates $states;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->states = new InMemoryAlbumMatchStates($this->clock);
    }

    #[\Override]
    protected function states(): AlbumMatchStateRepositoryInterface
    {
        return $this->states;
    }

    #[\Override]
    protected function album(): Uuid
    {
        return Uuid::v7();
    }

    #[\Override]
    protected function storeClock(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    #[\Override]
    protected function lapseClaims(): void
    {
        // To the claims' expiry and no further: a claim has lapsed at the instant its lease ends.
        $this->clock->sleep(self::LEASE);
    }
}
