<?php

namespace App\Modules\LogStreamer;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

class LogFileService
{
    public function getFiles(): Collection
    {
        return collect(File::allFiles(storage_path('logs')))
            ->filter(fn(SplFileInfo $log) => $log->getExtension() === 'log');
    }

    public function getSortedFiles()
    {
        return $this->getFiles()
            ->map(function (SplFileInfo $log) {
                return [
                    'fileName' => $log->getRelativePathname(),
                ];
            })
            ->sortByDesc('label')
            ->values();
    }
}