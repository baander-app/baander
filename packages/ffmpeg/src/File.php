<?php

namespace Baander\Ffmpeg;

use Amp\File as AmpFile;
use Baander\Ffmpeg\Exception\RuntimeException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * File constructor.
 * It is all about files
 */
class File
{
    /**
     * @param $dir
     * @return int|null
     */
    public static function directorySize(string $dir): int
    {
        if (AmpFile\isDirectory($dir)) {
            $size = 0;
            foreach (AmpFile\listFiles($dir) as $file) {
                if (in_array($file, [".", ".."])) continue;
                $filename = $dir . DIRECTORY_SEPARATOR . $file;
                $size += AmpFile\isFile($filename) ? AmpFile\getSize($filename) : static::directorySize($filename);
            }
            return $size;
        }

        return 0;
    }

    /**
     * @param $path
     * @param $content
     * @param bool $force
     * @return void
     */
    public static function put($path, $content, $force = true): void
    {
        if (AmpFile\exists($path) && !$force) {
            throw new RuntimeException("File Already Exists");
        }

        AmpFile\write($path, $content);
    }

    /**
     * @param string $prefix
     * @return string
     */
    public static function tmp($prefix = 'pfvs.file_'): string
    {
        for ($i = 0; $i < 10; ++$i) {
            $path = static::tmpDirPath() . '/' . basename($prefix) . uniqid(mt_rand());

            if (!AmpFile\exists($path)) {
                try {
                    AmpFile\openFile($path, 'x+');
                } catch (\Throwable) {
                    continue;
                }
            }

            return $path;
        }

        throw new RuntimeException("A temporary file could not be created.");
    }

    /**
     * @return string
     */
    private static function tmpDirPath(): string
    {
        static::makeDir($tmp_path = temp_path());
        return $tmp_path;
    }

    /**
     * @param $dirname
     * @param int $mode
     */
    public static function makeDir(string $dirname, int $mode = 0777): void
    {
        AmpFile\createDirectory($dirname, $mode);
    }

    /**
     * @param string $method
     * @param array $params
     * @return mixed
     */
    private static function filesystem(string $method, array $params)
    {
        try {
            return \call_user_func_array([new Filesystem, $method], $params);
        } catch (IOExceptionInterface $e) {
            throw new RuntimeException("Failed action" . $e->getPath(), $e->getCode(), $e);
        }
    }

    /**
     * @return string
     */
    public static function tmpDir(): string
    {
        static::makeDir($tmp_dir = static::tmpDirPath() . DIRECTORY_SEPARATOR . uniqid() . DIRECTORY_SEPARATOR);
        return $tmp_dir;
    }

    /**
     * clear all tmp files
     */
    public static function cleanTmpFiles(): void
    {
        static::remove(static::tmpDirPath());
    }

    /**
     * @param $dir
     */
    public static function remove(string $dir): void
    {
        AmpFile\deleteDirectory($dir);
    }

    /**
     * @param string $src
     * @param string $dst
     */
    public static function move(string $src, string $dst): void
    {
        static::filesystem('mirror', [$src, $dst]);
        static::remove($src);
    }

    /**
     * @param string $src
     * @param string $dst
     * @param bool $force
     */
    public static function copy(string $src, string $dst, bool $force = true): void
    {
        static::filesystem('copy', [$src, $dst, $force]);
    }
}