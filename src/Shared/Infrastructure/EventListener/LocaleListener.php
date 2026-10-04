<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 240)]
#[AsEventListener(event: KernelEvents::FINISH_REQUEST, method: 'resetLocale', priority: -240)]
final class LocaleListener
{
    private readonly LocaleAwareInterface $translator;

    public function __construct(
        TranslatorInterface $translator,
        private readonly string $defaultLocale = 'en',
    ) {
        if (!$translator instanceof LocaleAwareInterface) {
            throw new \InvalidArgumentException('The translator must support locale changes.');
        }

        $this->translator = $translator;
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
