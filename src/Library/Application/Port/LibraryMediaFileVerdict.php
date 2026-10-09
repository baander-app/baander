<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

/** What a deletion would do with one media file. */
enum LibraryMediaFileVerdict: string
{
    /** The file lies inside the library root and the server can unlink it. */
    case Deletable = 'deletable';

    /** Nothing exists at the path, or a symlink there points to nothing; only its index row goes. */
    case Missing = 'missing';

    /** The path, or the file a symlink at it points to, lies outside the library root; refuses the request. */
    case OutsideRoot = 'outside_root';

    /** The server cannot write the directory that holds the file; refuses the request. */
    case DirectoryNotWritable = 'directory_not_writable';

    public function refusesDeletion(): bool
    {
        return $this === self::OutsideRoot || $this === self::DirectoryNotWritable;
    }
}
