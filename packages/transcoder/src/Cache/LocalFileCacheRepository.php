<?php

namespace Baander\Transcoder\Cache;

use Amp\File;

class LocalFileCacheRepository
{
    public function __construct(
        private readonly string $cachePath,
        private readonly string $cacheFileSuffix = '.baander-tc',
    )
    {
    }

    public function suffixKey(string $key): string
    {
        return $key . $this->cacheFileSuffix;

    }

    public function get(string $key)
    {
        if (!File\exists($this->makeCacheKey($key))) {
            return null;
        }

        return File\read($this->suffixKey($key));
    }

    public function set(string $key, string $value)
    {
        File\write($this->makeCacheKey($key), $value);
    }

    private function makeCacheKey(string $key): string
    {
        return $this->joinPath($this->cachePath, $this->suffixKey($key));
    }

    private function joinPath(...$parts): string
    {
        return implode(DIRECTORY_SEPARATOR, $parts);
    }
}