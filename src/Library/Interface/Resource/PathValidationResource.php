<?php

declare(strict_types=1);

namespace App\Library\Interface\Resource;

use App\Library\Application\PathValidationResult;
use App\Shared\Interface\Resource\AbstractResource;

/** The `data` of POST /api/libraries/validate-path and `app:library:validate-path --json`. */
final class PathValidationResource extends AbstractResource
{
    /** @return array{valid: bool, error: ?string, resolvedPath: ?string, exists: bool, readable: bool} */
    public static function from(mixed $source): array
    {
        assert($source instanceof PathValidationResult);

        return [
            'valid' => $source->valid,
            'error' => $source->error,
            'resolvedPath' => $source->resolvedPath,
            'exists' => $source->exists,
            'readable' => $source->readable,
        ];
    }
}
