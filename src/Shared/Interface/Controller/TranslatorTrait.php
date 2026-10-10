<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Http\RequestLocale;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Translates in the language of the request being handled (RequestLocale), English outside one. */
trait TranslatorTrait
{
    private TranslatorInterface $translator;

    private ?RequestStack $translationRequests = null;

    #[Required]
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    #[Required]
    public function setTranslationRequests(RequestStack $requests): void
    {
        $this->translationRequests = $requests;
    }

    /** @param array<string, mixed> $parameters */
    protected function trans(
        string $id,
        array $parameters = [],
        ?string $domain = null,
        ?string $locale = null,
    ): string {
        $locale ??= RequestLocale::of($this->translationRequests?->getMainRequest()?->attributes->get(RequestLocale::ATTRIBUTE));

        return $this->translator->trans($id, $parameters, $domain, $locale);
    }
}
