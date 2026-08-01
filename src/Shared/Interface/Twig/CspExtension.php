<?php

declare(strict_types=1);

namespace App\Shared\Interface\Twig;

use App\Shared\Infrastructure\EventListener\CspNonceListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CspExtension extends AbstractExtension
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->getNonce(...)),
        ];
    }

    public function getNonce(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->attributes->getString(CspNonceListener::NONCE_ATTRIBUTE, '') ?? '';
    }
}
