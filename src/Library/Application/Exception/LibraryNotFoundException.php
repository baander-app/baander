<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\NotFoundException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** No library has the named UUID or slug; HTTP answers 404 in the request locale and console commands fail. */
final class LibraryNotFoundException extends NotFoundException implements TranslatableInterface
{
    private function __construct(private readonly string $identifier)
    {
        parent::__construct(sprintf('Library "%s" not found.', $identifier));
    }

    public static function forIdentifier(string $identifier): self
    {
        return new self($identifier);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('errors.not_found', ['library' => $this->identifier], 'library', $locale);
    }
}
