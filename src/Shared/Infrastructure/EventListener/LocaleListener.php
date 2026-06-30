<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 240)]
#[AsEventListener(event: KernelEvents::FINISH_REQUEST, method: 'resetLocale', priority: -240)]
final class LocaleListener
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly string $defaultLocale = 'en',
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $this->translator->setLocale($request->getLocale());
    }

    public function resetLocale(FinishRequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $this->translator->setLocale($this->defaultLocale);
    }
}
