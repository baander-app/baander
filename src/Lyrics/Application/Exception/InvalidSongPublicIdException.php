<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Exception;

use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The song's public ID is malformed; HTTP answers 422 in the request locale and console commands exit with INVALID. */
final class InvalidSongPublicIdException extends InvalidInputException implements TranslatableInterface
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Invalid public ID format.', previous: $previous);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('errors.invalid_public_id', [], null, $locale);
    }
}
