<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\ConflictException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Another scan holds the library's scan claim; HTTP answers 409 in the request locale and console commands fail. */
final class LibraryScanAlreadyRunningException extends ConflictException implements TranslatableInterface
{
    private function __construct(private readonly string $name)
    {
        parent::__construct(
            sprintf('A scan is already in progress for the library "%s".', $name),
            ['reason' => 'scan_in_progress'],
        );
    }

    public static function forLibrary(string $name): self
    {
        return new self($name);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('errors.scan_in_progress', ['library' => $this->name], 'library', $locale);
    }
}
