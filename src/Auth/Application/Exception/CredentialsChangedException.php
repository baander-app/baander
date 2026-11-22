<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The user's credentials changed after the credential behind this request was proved, so
 * nothing is changed or issued.
 *
 * Token issuance throws it when the login, the approval behind a code or the refresh chain
 * carries an earlier credential epoch; each grant answers it in its own protocol. A credential
 * change throws it when the session asking for the change was already ended, by another
 * credential change or by a revocation of its chain; CredentialsChangedListener answers that
 * with 401 and the reason credentials_changed.
 */
final class CredentialsChangedException extends RuntimeException implements TranslatableInterface
{
    public const string REASON = 'credentials_changed';

    public function __construct()
    {
        parent::__construct('The account\'s credentials changed after this authorization was granted.');
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('errors.credentials_changed', [], 'auth', $locale);
    }
}
