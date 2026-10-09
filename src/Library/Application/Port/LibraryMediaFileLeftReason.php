<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

/** Why a deletion left a file on disk. */
enum LibraryMediaFileLeftReason: string
{
    /** Checked again after the commit, the file resolved outside the library root. */
    case OutsideRoot = 'outside_root';

    /** The file system refused the unlink. */
    case UnlinkFailed = 'unlink_failed';
}
