<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Infrastructure\Filesystem;

use App\Library\Application\Port\LibraryMediaFileClaim;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Infrastructure\Filesystem\LibraryMediaFiles;
use App\Library\Infrastructure\Filesystem\MediaFileGuard;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

/**
 * Releasing the claim of a delete with files never throws, so it cannot hide the outcome of the
 * delete. A console delete can throw its interruption into the release; the release then tries
 * once more, so the claim still ends. An import forgets only the paths that are gone.
 */
final class LibraryMediaFilesReleaseTest extends TestCase
{
    public function testAReleaseCutShortIsRetriedSoTheClaimEnds(): void
    {
        $claim = new LibraryMediaFileClaim(new Uuid(), new Uuid());
        $libraries = $this->createMock(LibraryRepositoryInterface::class);
        $libraries->expects($this->exactly(2))->method('endDeleteClaim')
            ->with($claim->claimId)
            ->willReturnOnConsecutiveCalls(self::throwException(new RuntimeException('Interrupted by signal 15.')), true);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $this->files($libraries, $logger)->release($claim);
    }

    public function testAReleaseThatFailsTwiceIsLoggedAndNotThrown(): void
    {
        $claim = new LibraryMediaFileClaim(new Uuid(), new Uuid());
        $libraries = $this->createMock(LibraryRepositoryInterface::class);
        $libraries->expects($this->exactly(2))->method('endDeleteClaim')->willThrowException(new RuntimeException('Connection lost'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            self::stringContains('was not released'),
            self::callback(static fn (array $context): bool => $context['claim_id'] === $claim->claimId->toString() && $context['error'] === 'Connection lost'),
        );

        $this->files($libraries, $logger)->release($claim);
    }

    public function testForgetMissingRemovesTheIndexRowsOfTheMissingPathsOnly(): void
    {
        $base = sys_get_temp_dir() . '/baander-forget-missing-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($base));
        $present = $base . '/01.flac';
        self::assertNotFalse(file_put_contents($present, 'here'));
        $gone = $base . '/02.flac';
        $libraryId = new Uuid();

        try {
            $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
            $index->expects($this->once())->method('removeByPaths')->with($libraryId, [$gone]);
            self::assertSame([$gone], $this->files(index: $index)->forgetMissing($libraryId, [$present, $gone]));

            $untouched = $this->createMock(LibraryFileIndexRepositoryInterface::class);
            $untouched->expects($this->never())->method('removeByPaths');
            self::assertSame([], $this->files(index: $untouched)->forgetMissing($libraryId, [$present]));
        } finally {
            unlink($present);
            rmdir($base);
        }
    }

    private function files(
        ?LibraryRepositoryInterface $libraries = null,
        ?LoggerInterface $logger = null,
        ?LibraryFileIndexRepositoryInterface $index = null,
    ): LibraryMediaFiles {
        return new LibraryMediaFiles(
            $libraries ?? $this->createStub(LibraryRepositoryInterface::class),
            $index ?? $this->createStub(LibraryFileIndexRepositoryInterface::class),
            new MediaFileGuard(),
            $this->createStub(EntityManagerInterface::class),
            new MockClock(),
            $logger ?? new NullLogger(),
        );
    }
}
