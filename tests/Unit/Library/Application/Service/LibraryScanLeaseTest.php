<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application\Service;

use App\Library\Application\Exception\LibraryScanClaimLostException;
use App\Library\Application\Service\LibraryScanLease;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class LibraryScanLeaseTest extends TestCase
{
    public function testEveryWriteChecksOwnershipWithinTheHeartbeatThrottle(): void
    {
        $library = $this->library();
        $claim = new Uuid();
        $clock = new MockClock('2026-10-21 12:00:00 UTC');
        $transaction = new PassThroughTransaction();
        $libraries = $this->createMock(LibraryRepositoryInterface::class);
        $libraries->expects(self::never())->method('renewClaim');
        $libraries->expects(self::exactly(2))->method('renewScanClaimForWrite')
            ->with($library->getId(), $claim, 900)
            ->willReturnCallback(static function () use ($transaction): bool {
                self::assertTrue($transaction->active);
                return true;
            });
        $lease = new LibraryScanLease($libraries, $clock, $library, $claim, 900, 60);

        $lease->renew();
        $transaction->run($lease->guardWrite(...));
        $lease->renew();
        $transaction->run($lease->guardWrite(...));

        self::assertEquals(new \DateTimeImmutable('2026-10-21 12:00:00 UTC'), $clock->now());
    }

    public function testRefusedWriteGuardStopsTheScanWithoutEndingAnyClaim(): void
    {
        $libraries = $this->createMock(LibraryRepositoryInterface::class);
        $libraries->expects(self::once())->method('renewScanClaimForWrite')->willReturn(false);
        $libraries->expects(self::never())->method('renewClaim');
        $libraries->expects(self::never())->method('endScanClaim');
        $lease = new LibraryScanLease($libraries, new MockClock(), $this->library(), new Uuid(), 900, 60);

        $this->expectException(LibraryScanClaimLostException::class);
        (new PassThroughTransaction())->run($lease->guardWrite(...));
    }

    private function library(): Library
    {
        return Library::create('Media', new LibrarySlug('media'), new LibraryPath('/media'), LibraryType::Music, FilesystemType::Local);
    }
}
