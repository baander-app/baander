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
 *     $deletion = $files->prepareDeletion($libraryId, $paths);       // before the transaction
 *     $transaction->run(function () use ($files, $deletion): void {
 *         // ... delete the catalog rows ...
 *         $files->deleteIndexRows($deletion);                        // inside the same transaction
 *     });
 *     $result = $files->deleteFiles($deletion);                      // after the commit
 *
 * The preparation refuses the whole request before anything changes. With the index rows gone, a
 * file the deletion leaves on disk, or one a crash after the commit leaves, reads as new to the
 * next incremental scan and is imported again. A path is the absolute path the scanner stored.
 */
interface LibraryMediaFilesInterface
{
    /**
     * Checks each path and whether a scan holds the library, and changes nothing; a preview shows
     * the verdicts, and prepareDeletion() refuses what this reports.
     *
     * @param list<string> $paths
     *
     * @throws NotFoundException when no library has the ID
     */
    public function inspect(Uuid $libraryId, array $paths): LibraryMediaFileInspection;

    /**
     * Checks the paths as inspect() does and refuses the whole request when any check fails.
     *
     * @param list<string> $paths
     *
     * @return LibraryMediaFileInspection a deletion that deleteIndexRows() and deleteFiles() carry out
     *
     * @throws NotFoundException     when no library has the ID
     * @throws ConflictException     when a scan holds a live claim on the library, the library root is
     *                               not an existing directory (unmounted storage), or the server
     *                               cannot write a directory that holds one of the files
     * @throws InvalidInputException when a path, or the file a symlink at it points to, lies outside the library root
     */
    public function prepareDeletion(Uuid $libraryId, array $paths): LibraryMediaFileInspection;

    /**
     * Deletes the file index rows of every path of the deletion, on the connection and in the
     * transaction of the caller, which must have begun one.
     */
    public function deleteIndexRows(LibraryMediaFileInspection $deletion): void;

    /**
     * After the commit, checks each file again and unlinks it. A symlink is removed as a link and
     * its target is kept. A file that now resolves outside the root, or cannot be unlinked, is
     * reported as left.
     */
    public function deleteFiles(LibraryMediaFileInspection $deletion): LibraryMediaFileDeletionResult;
}
