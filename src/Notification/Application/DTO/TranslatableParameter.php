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

    /**
     * Translates every translatable value into the locale and keeps the others.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public static function resolveAll(array $parameters, TranslatorInterface $translator, string $locale): array
    {
        return array_map(
            static fn (mixed $value): mixed => $value instanceof TranslatableInterface ? $value->trans($translator, $locale) : $value,
            $parameters,
        );
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->key, [], 'notification', $locale);
    }
}
