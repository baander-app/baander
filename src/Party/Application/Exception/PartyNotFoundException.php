<?php

declare(strict_types=1);

namespace App\Party\Application\Exception;

use App\Shared\Application\Exception\NotFoundException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The party does not exist, or the caller may not learn that it does.
 *
 * It translates the message key a route's notFound() answers with, so a refused caller gets the
 * same body as for a party that does not exist, in every request language.
 */
final class PartyNotFoundException extends NotFoundException implements TranslatableInterface
{
    public function __construct()
    {
        parent::__construct('Resource not found.');
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('errors.not_found.resource', [], 'messages', $locale);
    }
}
