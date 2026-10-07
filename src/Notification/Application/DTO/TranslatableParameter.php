<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A notification message parameter whose value is itself a message in the
 * notification domain, such as the word used when an event has no name.
 * Each email translates it in its recipient's language.
 */
final readonly class TranslatableParameter implements TranslatableInterface
{
    public function __construct(
        public string $key,
    ) {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->key, [], 'notification', $locale);
    }
}
