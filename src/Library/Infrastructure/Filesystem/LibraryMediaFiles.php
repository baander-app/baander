<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Filesystem;

use App\Library\Application\Exception\LibraryMediaDirectoryNotWritableException;
use App\Library\Application\Exception\LibraryMediaFileOutsideRootException;
use App\Library\Application\Exception\LibraryMediaFilesAllMissingException;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Exception\LibraryRootUnavailableException;
use App\Library\Application\Port\LibraryMediaFileCheck;
use App\Library\Application\Port\LibraryMediaFileClaim;
use App\Library\Application\Port\LibraryMediaFileDeletionResult;
use App\Library\Application\Port\LibraryMediaFileInspection;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Application\Port\LibraryMediaFileVerdict;
use App\Library\Application\Service\LibraryClaimRenewal;
use App\Library\Application\Service\LibraryScanClaims;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryClaimKind;
use App\Shared\Domain\Model\Uuid;
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

    public function isHeldByDelete(Uuid $libraryId): bool
    {
        return $this->libraries->liveClaimKind($libraryId) === LibraryClaimKind::Delete;
    }

    public function isHeldByDeleteForImport(Uuid $libraryId): bool
    {
        return $this->libraries->liveClaimKindForImport($libraryId) === LibraryClaimKind::Delete;
    }

    public function forgetMissing(Uuid $libraryId, array $paths): array
    {
        // PHP caches file status; a file deleted since an earlier check must count.
        $missing = array_values(array_filter($paths, static function (string $path): bool {
            clearstatcache(true, $path);

            return !file_exists($path);
        }));

        // The scan indexed these paths when it found the files. Left in the index, a file that
        // comes back unchanged would read as known to every incremental scan and never be imported.
        if ($missing !== []) {
            $this->fileIndex->removeByPaths($libraryId, $missing);
        }

        return $missing;
    }

    public function claim(Uuid $libraryId): LibraryMediaFileClaim
    {
        $library = $this->library($libraryId);
        $claimId = new Uuid();
        LibraryScanClaims::ensureClaimed(
            $library,
            $this->libraries->claimDelete($library->getId(), $claimId, LibraryScanClaims::DEFAULT_LEASE_SECONDS),
        );

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

        if ($inspection->allFilesMissing()) {
            throw LibraryMediaFilesAllMissingException::underRoot($library->getPath()->toString());
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
        $renewal = new LibraryClaimRenewal(
            $this->libraries,
            $this->clock,
            $claim->claimId,
            LibraryScanClaims::DEFAULT_LEASE_SECONDS,
            LibraryScanClaims::RENEWAL_INTERVAL_SECONDS,
            renewedAt: null,
        );

        return $this->guard->delete($deletion, $renewal->renew(...));
    }

    public function release(LibraryMediaFileClaim $claim): void
    {
        try {
            try {
                $this->libraries->endDeleteClaim($claim->claimId);
            } catch (Throwable) {
                // Ending the claim is idempotent. A second attempt also ends it when the first was
                // cut short by an interruption that a console delete throws into it.
                $this->libraries->endDeleteClaim($claim->claimId);
            }
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
