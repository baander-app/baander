<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;

/**
 * Deletes the audio files of catalog entries that are being deleted, confined to their library's
 * root, so the API and the console share one guard. A delete with files calls it in this order:
 *
 *     $claim = $files->claim($libraryId);                                // before anything else
 *     try {
 *         $deletion = $files->prepareDeletion($claim, $paths);
 *         $transaction->run(function () use ($files, $deletion): void {
 *             // ... delete the catalog rows ...
 *             $files->deleteIndexRows($deletion);                        // inside the same transaction
 *         });
 *         $result = $files->deleteFiles($claim, $deletion);              // after the commit
 *     } finally {
 *         $files->release($claim);                                       // also when anything failed
 *     }
 *
 * The claim holds the library for the whole delete, so no scan indexes the files it removes; it
 * leaves the library's scan status and last scan time alone. The preparation refuses the whole
 * request before anything changes. With the index rows gone, a file the deletion leaves on disk,
 * or one a crash after the commit leaves, reads as new to the next incremental scan and is
 * imported again. A path is the absolute path the scanner stored.
 */
interface LibraryMediaFilesInterface
{
    /**
     * Checks each path and whether a scan or another delete with files holds the library, and
     * changes nothing; a preview shows the verdicts, and a delete refuses what this reports.
     *
     * @param list<string> $paths
     *
     * @throws NotFoundException when no library has the ID
     */
    public function inspect(Uuid $libraryId, array $paths): LibraryMediaFileInspection;

    /**
     * Whether a delete with files holds a live claim on the library. An import of files the
     * scanner found waits while one does, since it could import a file the delete is about to
     * unlink. False for an unknown library.
     */
    public function isHeldByDelete(Uuid $libraryId): bool;

    /**
     * Answers as isHeldByDelete() does, inside the caller's transaction, which must be open, and
     * keeps a delete with files from claiming the library until that transaction ends. An import
     * that writes songs in the same transaction only when this returns false never writes a song
     * that such a delete misses, or one whose file it is about to unlink: the delete's claim
     * waits for the write to commit, and a write after the claim sees it.
     */
    public function isHeldByDeleteForImport(Uuid $libraryId): bool;

    /**
     * The paths at which no file exists now, or a symlink points to nothing, in request order,
     * after deleting their file index rows. An import drops these paths, so a file deleted since
     * the scan found it becomes no song or video; without its index row, a file that comes back
     * at the path unchanged reads as new to the next incremental scan, which imports it.
     *
     * @param list<string> $paths absolute paths, as the scanner stored them
     *
     * @return list<string>
     */
    public function forgetMissing(Uuid $libraryId, array $paths): array;

    /**
     * Claims the library for a delete with files, with a lease that deleteFiles() renews. A claim
     * whose holder stopped renewing it has lapsed and is taken over; a lapsed scan claim belonged
     * to a scan that died, which is marked failed. It first waits for the import writes in flight
     * on the library (see isHeldByDeleteForImport()) to commit, so the catalog rows a caller
     * reads after the claim include every song an import wrote.
     *
     * @throws NotFoundException when no library has the ID
     * @throws ConflictException when a scan or another delete with files holds a live claim on the
     *                           library (reason `library_busy`, with the holder's kind)
     */
    public function claim(Uuid $libraryId): LibraryMediaFileClaim;

    /**
     * Checks the paths as inspect() does, under the delete's own claim, and refuses the whole
     * request when any check fails.
     *
     * @param list<string> $paths
     *
     * @return LibraryMediaFileInspection a deletion that deleteIndexRows() and deleteFiles() carry out
     *
     * @throws NotFoundException     when the library is gone
     * @throws ConflictException     when the library root is not an existing directory (unmounted
     *                               storage), every requested file is missing (reason
     *                               `all_files_missing`; a request without paths is not refused
     *                               for it), or the server cannot write a directory that holds
     *                               one of the files
     * @throws InvalidInputException when a path, or the file a symlink at it points to, lies outside the library root
     */
    public function prepareDeletion(LibraryMediaFileClaim $claim, array $paths): LibraryMediaFileInspection;

    /**
     * Deletes the file index rows of every path of the deletion, on the connection and in the
     * transaction of the caller, which must have begun one.
     */
    public function deleteIndexRows(LibraryMediaFileInspection $deletion): void;

    /**
     * After the commit, renews the claim, checks each file again and unlinks it. A symlink is
     * removed as a link and its target is kept. A file that now resolves outside the root, or
     * cannot be unlinked, is reported as left; so is every file not yet unlinked when the claim
     * turns out lost to another holder, which happens only after the delete went longer than its
     * lease without renewing it.
     */
    public function deleteFiles(LibraryMediaFileClaim $claim, LibraryMediaFileInspection $deletion): LibraryMediaFileDeletionResult;

    /**
     * Ends the claim. Does nothing when it already ended or another holder took it over. A
     * failure to end it is retried once, then logged rather than thrown, so that it never hides
     * the outcome of the delete; the claim then lapses with its lease.
     */
    public function release(LibraryMediaFileClaim $claim): void;
}
