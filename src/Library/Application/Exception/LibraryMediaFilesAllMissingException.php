<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\ConflictException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every file of a delete with files reads as missing, as when the storage under part of the
 * library is not mounted. Deleting their index rows would let the next scan import them again
 * once the storage is back, so nothing is deleted.
 */
final class LibraryMediaFilesAllMissingException extends ConflictException implements TranslatableInterface
{
    private function __construct(private readonly string $root)
    {
        parent::__construct(
            sprintf('None of the files exist in the library folder %s; its storage may not be mounted. Nothing was deleted.', $root),
            ['reason' => 'all_files_missing', 'root' => $root],
        );
    }

    public static function underRoot(string $root): self
    {
        return new self($root);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('errors.all_files_missing', ['root' => $this->root], 'library', $locale);
    }
}
