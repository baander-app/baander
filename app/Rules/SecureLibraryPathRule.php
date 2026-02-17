<?php

namespace App\Rules;

use App\Modules\Security\Exceptions\PathSecurityException;
use App\Modules\Security\PathSecurityService;
use Illuminate\Contracts\Validation\ValidationRule;

class SecureLibraryPathRule implements ValidationRule
{
    private PathSecurityService $pathSecurity;

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $this->pathSecurity = app(PathSecurityService::class);

        if (!is_string($value)) {
            $fail('Path is missing');
            return;
        }

        try {
            $resolvedPath = $this->pathSecurity->resolveAndValidateSymlink($value);
        } catch (PathSecurityException $e) {
            $fail($this->errorMessage($e));
            return;
        }

        $allowedPaths = $this->parseAllowedPaths();
        if (!$this->pathSecurity->isWithinAllowedPath($resolvedPath, $allowedPaths)) {
            $fail('Not within allowed path');
            return;
        }

        $depth = $this->pathSecurity->calculateDirectoryDepth($resolvedPath);
        $maxDepth = config('scanner.security.max_directory_depth', 20);
        if ($depth > $maxDepth) {
            $fail('Too deep');
            return;
        }

        if (!\File::exists($value) || !\File::isReadable($value)) {
            $fail('File not accessible');
            return;
        }
    }

    public function message(): string
    {
        $allowedPaths = $this->parseAllowedPaths();
        $maxDepth = config('scanner.security.max_directory_depth', 20);

        return sprintf(
            'The library path must be within: %s, not exceed depth %d, and be readable.',
            implode(', ', $allowedPaths),
            $maxDepth
        );
    }

    private function parseAllowedPaths(): array
    {
        $paths = config('scanner.security.allowed_base_paths', []);

        if (is_string($paths)) {
            return array_map('trim', explode(',', $paths));
        }

        return $paths;
    }

    private function errorMessage(PathSecurityException $e): string
    {
        return match (true) {
            str_contains($e->getMessage(), 'Path traversal attempt') => 'The library path contains invalid characters or path traversal attempts.',
            str_contains($e->getMessage(), 'Circular symlink') => 'The library path contains circular symlinks.',
            str_contains($e->getMessage(), 'depth exceeded') => 'The library path is too deep.',
            str_contains($e->getMessage(), 'outside allowed paths') => 'The library path must be within an allowed directory.',
            str_contains($e->getMessage(), 'not readable') => 'The library path is not readable.',
            str_contains($e->getMessage(), 'not exist') => 'The library path does not exist.',
            default => 'The library path is invalid.',
        };
    }
}
