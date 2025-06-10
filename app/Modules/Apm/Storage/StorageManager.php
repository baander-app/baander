<?php

namespace App\Modules\Apm\Storage;

use App\Modules\Apm\Listeners\FilesystemListener;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\App;

/**
 * Extended Storage Manager with APM tracking
 */
class StorageManager extends FilesystemManager
{
    private FilesystemListener $filesystemListener;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->filesystemListener = App::make(FilesystemListener::class);
    }

    /**
     * Override disk method to return instrumented disk
     */
    public function disk($name = null)
    {
        $disk = parent::disk($name);
        return new InstrumentedFilesystemAdapter($disk, $this->filesystemListener, $name);
    }
}
