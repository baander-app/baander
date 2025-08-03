<?php

namespace App\Modules\Logging\LogStreamer;

use App\Modules\Logging\LogStreamer\Models\LogFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

class LogFileService
{
    public function getSortedFiles()
    {
        return $this->getFiles()
            ->sortByDesc(fn(LogFile $log) => $log->createdAt)
            ->values();
    }

    /**
     * @return Collection<LogFile>
     */
    public function getFiles(): Collection
    {
        return collect(File::allFiles(storage_path('logs')))
            ->map(function (SplFileInfo $log) {
                return new LogFile(
                    id: hash('sha256', $log->getRealPath()),
                    fileName: $log->getRelativePathname(),
                    path: $log->getRealPath(),
                    createdAt: Carbon::createFromTimestamp($log->getCTime()),
                    updatedAt: Carbon::createFromTimestamp($log->getMTime()),
                );
            });
    }

    public function getFileById(string $id): ?LogFile
    {
        return $this->getFiles()->firstWhere(fn(LogFile $log) => $log->id === $id);
    }
}