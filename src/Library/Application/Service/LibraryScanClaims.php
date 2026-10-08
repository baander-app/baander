<?php

declare(strict_types=1);

namespace App\Library\Application\Service;

use App\Library\Application\DTO\LibraryScanClaimResult;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Exception\LibraryScanAlreadyRunningException;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;

/**
 * Starting a scan claims the library first: one conditional update that only
 * succeeds while no scan runs. The web and console paths both start scans through it.
 */
final readonly class LibraryScanClaims
{
    public function __construct(
        private LibraryLookup $lookup,
        private LibraryRepositoryInterface $libraries,
    ) {
    }

    /**
     * @return Library the claimed library, with its `scanning` status
     *
     * @throws LibraryNotFoundException
     * @throws LibraryScanAlreadyRunningException
     */
    public function claim(string $identifier): Library
    {
        $library = $this->lookup->byIdentifier($identifier);

        return $this->claimLibrary($library)
            ?? throw LibraryScanAlreadyRunningException::forLibrary($library->getName());
    }

    /** Claims every library that is not scanning; the others are skipped. */
    public function claimAll(): LibraryScanClaimResult
    {
        $claimed = [];
        $skipped = [];
        foreach ($this->libraries->findAllOrdered() as $library) {
            $claimedLibrary = $this->claimLibrary($library);
            if ($claimedLibrary === null) {
                $skipped[] = $library;
            } else {
                $claimed[] = $claimedLibrary;
            }
        }

        return new LibraryScanClaimResult($claimed, $skipped);
    }

    /**
     * @return bool whether a claim was released; false when no scan held one
     *
     * @throws LibraryNotFoundException
     */
    public function release(string $identifier): bool
    {
        return $this->libraries->releaseScanClaim($this->lookup->byIdentifier($identifier)->getId());
    }

    private function claimLibrary(Library $library): ?Library
    {
        if (!$this->libraries->claimScan($library->getId())) {
            return null;
        }

        return $this->libraries->findByUuid($library->getId())
            ?? throw LibraryNotFoundException::forIdentifier($library->getId()->toString());
    }
}
