<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\ConflictException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The new library's root lies inside another library's root, contains it or is the same
 * directory, so a file would belong to both; the details name the other library by slug.
 * HTTP answers 409 in the request locale and console commands fail.
 */
final class LibraryRootOverlapsException extends ConflictException implements TranslatableInterface
{
    private function __construct(private readonly string $path, private readonly string $library, private readonly string $root)
    {
        parent::__construct(
            sprintf('The path "%s" lies inside or contains the root "%s" of the library "%s".', $path, $root, $library),
            ['reason' => 'root_overlaps', 'library' => $library],
        );
    }

    public static function with(string $path, string $library, string $root): self
    {
        return new self($path, $library, $root);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans(
            'errors.root_overlaps',
            ['path' => $this->path, 'library' => $this->library, 'root' => $this->root],
            'library',
            $locale,
        );
    }
}
