<?php

namespace App\Modules\Http\Concerns;

interface DirectStreamableFile
{
    /**
     * Get the path of the file
     */
    public function getPath(): string;

    /**
     * Get the size of the file in bytes
     */
    public function getSize(): int;

    /**
     * Get the mime type of the file
     */
    public function getMimeType(): string;
}