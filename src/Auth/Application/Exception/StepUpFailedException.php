<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The current password, or the TOTP code an account with TOTP needs, did not confirm a
 * sensitive change. One answer for every mismatch, so it never says which part was wrong.
 */
final class StepUpFailedException extends InvalidInputException implements TranslatableInterface
{
    public const string REASON = 'step_up_failed';

    public static function create(): self
    {
        return new self('The password or code is incorrect.', ['reason' => self::REASON]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('errors.step_up_failed', [], 'auth', $locale);
    }
}
