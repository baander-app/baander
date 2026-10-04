<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\EventListener;

use App\Shared\Infrastructure\EventListener\LocaleListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LocaleListenerTest extends TestCase
{
    public function testTranslatorWithoutLocaleMutationIsRejectedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The translator must support locale changes.');

        new LocaleListener($this->createStub(TranslatorInterface::class));
    }

    public function testApiRequestSetsLocaleAndFinishResetsTheSameTranslator(): void
    {
        $translator = new Translator('en');
        $listener = new LocaleListener($translator);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/api/health');
        $request->setLocale('fr');

        $listener(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame('fr', $translator->getLocale());

        $listener->resetLocale(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame('en', $translator->getLocale());
    }

    public function testNonApiRequestDoesNotChangeTranslatorLocale(): void
    {
        $translator = new Translator('de');
        $listener = new LocaleListener($translator);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/');
        $request->setLocale('fr');

        $listener(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->resetLocale(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame('de', $translator->getLocale());
    }
}
