<?php

namespace App\Services;

use Symfony\Component\Finder\Finder;

class OpCacheService
{
    public function compile($force = false)
    {
        if (!ini_get('opcache.dups_fix') && !$force) {
            return ['message' => 'opcache.dups_fix must be enabled, or run with --force'];
        }

        $compiled = 0;

        // Get files in these paths
        $files = collect(Finder::create()->in(config('opcache.directories'))
            ->name('*.php')
            ->ignoreUnreadableDirs()
            ->notContains('#!/usr/bin/env php')
            ->exclude(config('opcache.exclude'))
            ->files()
            ->followLinks());

        // optimized files
        $files->each(function ($file) use (&$compiled) {
            try {
                if (!opcache_is_script_cached($file)) {
                    opcache_compile_file($file);
                }

                $compiled++;
            } catch (\Exception $e) {
            }
        });

        return [
            'total_files_count' => $files->count(),
            'compiled_count'    => $compiled,
        ];
    }
}