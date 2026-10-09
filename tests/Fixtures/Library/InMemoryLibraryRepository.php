<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Library;

use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Domain\ValueObject\ScanClaimRelease;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Libraries and their scan claims in memory, with leases that lapse on the given clock, for
 * unit tests of the claim and scan use cases. LibraryScanClaimTest checks the same rules
 * against the production repository on PostgreSQL.
 */
final class InMemoryLibraryRepository implements LibraryRepositoryInterface
{
    /** @var array<string, Library> */
    private array $libraries = [];
    /** @var array<string, array{claim: string, expiresAt: DateTimeImmutable}> library ID => claim */
    private array $claims = [];
    /** @var list<string> every claim statement, in order, for assertions */
    public array $log = [];
    /** Thrown by findBySlug, to fail a library lookup. */
    public ?\Throwable $lookupFailure = null;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function add(Library $library): Library
    {
        $this->libraries[$library->getId()->toString()] = $library;

        return $library;
    }

    /** The claim ID holding the library, live or lapsed. */
    public function claimOf(Library $library): ?string
    {
        return $this->claims[$library->getId()->toString()]['claim'] ?? null;
    }

    public function findVisible(LibraryReadScope $scope, ?LibraryType $type = null): array
    {
        return $type === null ? $this->findAllOrdered() : $this->findByType($type);
    }

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Library
    {
        return $this->findByUuid($uuid);
    }

    public function findVisibleBySlug(LibrarySlug $slug, LibraryReadScope $scope): ?Library
    {
        return $this->findBySlug($slug);
    }

    public function save(Library $library): void
    {
        $this->add($library);
    }

    public function findByUuid(Uuid $uuid): ?Library
    {
        return $this->libraries[$uuid->toString()] ?? null;
    }

    public function findBySlug(LibrarySlug $slug): ?Library
    {
        if ($this->lookupFailure !== null) {
            throw $this->lookupFailure;
        }

        return array_find($this->libraries, static fn (Library $library): bool => $library->getSlug()->toString() === $slug->toString());
    }

    public function findByType(LibraryType $type): array
    {
        return array_values(array_filter($this->libraries, static fn (Library $library): bool => $library->getType() === $type));
    }

    public function findAllOrdered(): array
    {
        return array_values($this->libraries);
    }

    public function findAccessibleByUser(Uuid $userId): array
    {
        return [];
    }

    public function delete(Library $library): void
    {
        unset($this->libraries[$library->getId()->toString()], $this->claims[$library->getId()->toString()]);
    }

    public function claimScan(Uuid $libraryId, Uuid $claimId, int $leaseSeconds): bool
    {
        $id = $libraryId->toString();
        $held = $this->claims[$id] ?? null;
        if (!isset($this->libraries[$id]) || ($held !== null && $held['claim'] !== $claimId->toString() && !$this->lapsed($held))) {
            $this->log[] = 'refused ' . $this->slug($id);

            return false;
        }

        $this->claims[$id] = ['claim' => $claimId->toString(), 'expiresAt' => $this->clock->now()->modify(sprintf('+%d seconds', $leaseSeconds))];
        $this->libraries[$id]->getState()->discoveryStatus = 'scanning';
        $this->log[] = 'claimed ' . $this->slug($id);

        return true;
    }

    public function renewScanClaim(Uuid $claimId, int $leaseSeconds): bool
    {
        $id = $this->holder($claimId);
        if ($id === null) {
            $this->log[] = 'renewal refused';

            return false;
        }
        $this->claims[$id]['expiresAt'] = $this->clock->now()->modify(sprintf('+%d seconds', $leaseSeconds));
        $this->log[] = 'renewed ' . $this->slug($id);

        return true;
    }

    public function endScanClaim(Uuid $claimId, bool $completed): bool
    {
        $id = $this->holder($claimId);
        if ($id === null) {
            $this->log[] = 'end refused';

            return false;
        }
        unset($this->claims[$id]);
        $state = $this->libraries[$id]->getState();
        $state->discoveryStatus = $completed ? 'completed' : 'failed';
        if ($completed) {
            $state->lastScan = $this->clock->now();
        }
        $this->log[] = ($completed ? 'completed ' : 'failed ') . $this->slug($id);

        return true;
    }

    public function releaseScanClaim(Uuid $libraryId, bool $evenIfLive): ScanClaimRelease
    {
        $id = $libraryId->toString();
        $held = $this->claims[$id] ?? null;
        if ($held === null) {
            return ScanClaimRelease::NoClaim;
        }
        if (!$evenIfLive && !$this->lapsed($held)) {
            return ScanClaimRelease::Live;
        }
        unset($this->claims[$id]);
        $this->libraries[$id]->getState()->discoveryStatus = 'failed';
        $this->log[] = 'released ' . $this->slug($id);

        return ScanClaimRelease::Released;
    }

    /** @param array{claim: string, expiresAt: DateTimeImmutable} $claim */
    private function lapsed(array $claim): bool
    {
        return $claim['expiresAt'] <= $this->clock->now();
    }

    private function holder(Uuid $claimId): ?string
    {
        $id = array_find_key($this->claims, static fn (array $claim): bool => $claim['claim'] === $claimId->toString());

        return is_string($id) ? $id : null;
    }

    private function slug(string $libraryId): string
    {
        return $this->libraries[$libraryId]->getSlug()->toString();
    }
}
