<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Filesystem;

use App\Library\Application\Exception\LibraryBusyException;
use App\Library\Application\Exception\LibraryMediaDirectoryNotWritableException;
use App\Library\Application\Exception\LibraryMediaFileOutsideRootException;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Exception\LibraryRootUnavailableException;
use App\Library\Application\Port\LibraryMediaFileCheck;
use App\Library\Application\Port\LibraryMediaFileClaim;
use App\Library\Application\Port\LibraryMediaFileDeletionResult;
use App\Library\Application\Port\LibraryMediaFileInspection;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Application\Port\LibraryMediaFileVerdict;
use App\Library\Application\Service\LibraryScanClaims;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class LibraryMediaFiles implements LibraryMediaFilesInterface
{
    public function __construct(
        private LibraryRepositoryInterface $libraries,
        private LibraryFileIndexRepositoryInterface $fileIndex,
        private MediaFileGuard $guard,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function inspect(Uuid $libraryId, array $paths): LibraryMediaFileInspection
    {
        $library = $this->library($libraryId);

        return $this->guard->inspect(
            $library->getId(),
            $library->getPath()->toString(),
            $paths,
            $this->libraries->liveClaimKind($library->getId()) !== null,
        );
    }

    public function claim(Uuid $libraryId): LibraryMediaFileClaim
    {
        $library = $this->library($libraryId);
        $claimId = new Uuid();
        $attempt = $this->libraries->claimDelete($library->getId(), $claimId, LibraryScanClaims::DEFAULT_LEASE_SECONDS);
        if (!$attempt->claimed) {
            throw $attempt->holder === null
                ? LibraryNotFoundException::forIdentifier($libraryId->toString())
                : LibraryBusyException::heldBy($library->getName(), $attempt->holder);
        }

        return new LibraryMediaFileClaim($library->getId(), $claimId);
    }

    public function prepareDeletion(LibraryMediaFileClaim $claim, array $paths): LibraryMediaFileInspection
    {
        $library = $this->library($claim->libraryId);
        // The library's live claim is this delete's own, so it does not count as busy.
        $inspection = $this->guard->inspect($library->getId(), $library->getPath()->toString(), $paths, libraryBusy: false);

        if (!$inspection->rootAvailable) {
            throw LibraryRootUnavailableException::forRoot($library->getPath()->toString());
        }

        $outside = $inspection->withVerdict(LibraryMediaFileVerdict::OutsideRoot);
        if ($outside !== []) {
            throw LibraryMediaFileOutsideRootException::forPaths(
                $library->getName(),
                array_map(static fn (LibraryMediaFileCheck $file): string => $file->path, $outside),
            );
        }

        $unwritable = $inspection->withVerdict(LibraryMediaFileVerdict::DirectoryNotWritable);
        if ($unwritable !== []) {
            throw LibraryMediaDirectoryNotWritableException::forDirectories(array_values(array_unique(
                array_map(static fn (LibraryMediaFileCheck $file): string => (string) $file->directory, $unwritable),
            )));
        }

        return $inspection;
    }

    public function deleteIndexRows(LibraryMediaFileInspection $deletion): void
    {
        // Outside the caller's transaction the rows would go even when its catalog delete fails.
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new LogicException('Delete the file index rows inside the transaction that deletes the catalog rows.');
        }

        $this->fileIndex->removeByPaths($deletion->libraryId, $deletion->paths());
    }

    public function deleteFiles(LibraryMediaFileClaim $claim, LibraryMediaFileInspection $deletion): LibraryMediaFileDeletionResult
    {
        // The first file renews the claim, which also proves it is still this delete's after the
        // transaction; later files renew it once per renewal interval, as a scan does.
        $renewedAt = null;
        $stillClaimed = function () use ($claim, &$renewedAt): bool {
            $now = $this->clock->now();
            if ($renewedAt instanceof DateTimeImmutable) {
                $elapsed = $now->getTimestamp() - $renewedAt->getTimestamp();
                // A wall clock set back counts as due, so a clock change cannot starve the lease.
                if ($elapsed >= 0 && $elapsed < LibraryScanClaims::RENEWAL_INTERVAL_SECONDS) {
                    return true;
                }
            }
            if (!$this->libraries->renewClaim($claim->claimId, LibraryScanClaims::DEFAULT_LEASE_SECONDS)) {
                return false;
            }
            $renewedAt = $now;

            return true;
        };

        return $this->guard->delete($deletion, $stillClaimed);
    }

    public function release(LibraryMediaFileClaim $claim): void
    {
        try {
            $this->libraries->endDeleteClaim($claim->claimId);
        } catch (Throwable $exception) {
            $this->logger->error('The claim of a delete with files was not released; it lapses with its lease, or run app:library:scan <library> --release --force', [
                'library_id' => $claim->libraryId->toString(),
                'claim_id' => $claim->claimId->toString(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function library(Uuid $libraryId): Library
    {
        return $this->libraries->findByUuid($libraryId)
            ?? throw LibraryNotFoundException::forIdentifier($libraryId->toString());
    }
}
