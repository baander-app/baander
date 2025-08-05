<?php

declare(strict_types=1);

namespace App\Modules\Essentia;

use App\Modules\Essentia\Exceptions\EssentiaException;
use FFI;

/**
 * FFI wrapper for Essentia C++ library
 */
class EssentiaFFI
{
    private static ?FFI $ffi = null;
    private static ?string $libraryPath = null;

    public function __construct(?string $libraryPath = null)
    {
        self::$libraryPath = $libraryPath ?? $this->findEssentiaLibrary();
        
        if (!self::$ffi) {
            $this->initializeFFI();
        }
    }

    public function getFFI(): FFI
    {
        if (!self::$ffi) {
            throw new EssentiaException('FFI not initialized');
        }
        
        return self::$ffi;
    }

    private function initializeFFI(): void
    {
        try {
            $headerFile = $this->findHeaderFile();
            $header = file_get_contents($headerFile);
            
            self::$ffi = FFI::cdef($header, self::$libraryPath);
            
        } catch (\Exception $e) {
            throw new EssentiaException("Failed to initialize Essentia FFI: {$e->getMessage()}", 0, $e);
        }
    }

    private function findEssentiaLibrary(): string
    {
        $possiblePaths = config('services.essentia.library_path');

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new EssentiaException('Essentia library not found. Please install Essentia or specify the library path.');
    }

    private function findHeaderFile(): string
    {
        $possiblePaths = config('services.essentia.header_path');

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new EssentiaException('Essentia C header file not found.');
    }

    public static function version(): string
    {
        return '1.0.0';
    }
}