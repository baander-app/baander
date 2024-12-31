<?php

namespace Baander\Ffmpeg;

class Utils
{
    /**
     * @param string $str
     * @return string
     */
    public static function appendSlash(string $str): string
    {
        return $str ? rtrim($str, '/') . "/" : $str;
    }

    /**
     * @param array $array
     * @param string $glue
     */
    public static function concatKeyValue(array &$array, string $glue = ""): void
    {
        array_walk($array, function (&$value, $key) use ($glue) {
            $value = "$key$glue$value";
        });
    }

    /**
     * @param array $array
     * @param string $start_with
     * @return array
     */
    public static function arrayToFFmpegOpt(array $array, string $start_with = "-"): array
    {
        $new = [];
        foreach ($array as $key => $value) {
            if (is_string($key)) {
                array_push($new, $start_with . $key, $value);
            } else {
                $new = null;
                break;
            }
        }

        return $new ?? $array;
    }

    /**
     * @return string
     */
    public static function getOS(): string
    {
        return match (true) {
            stristr(PHP_OS, 'DAR') => "osX",
            stristr(PHP_OS, 'WIN') => "windows",
            stristr(PHP_OS, 'LINUX') => "linux",
            default => "unknown",
        };
    }

    /**
     * @param bool $isAutoSelect
     * @return string
     */
    public static function convertBooleanToYesNo(bool $isAutoSelect): string
    {
        return $isAutoSelect ? "YES" : "NO";
    }
}