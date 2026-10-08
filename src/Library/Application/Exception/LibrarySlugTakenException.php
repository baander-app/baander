<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\ConflictException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Another library already has the slug; HTTP answers 409 in the request locale and console commands fail. */
final class LibrarySlugTakenException extends ConflictException implements TranslatableInterface
{
    private function __construct(private readonly string $slug)
    {
        parent::__construct(sprintf('A library with the slug "%s" already exists.', $slug), ['reason' => 'slug_exists']);
    }

    public static function forSlug(string $slug): self
    {
        return new self($slug);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('errors.slug_exists', ['slug' => $this->slug], 'library', $locale);
    }
}
