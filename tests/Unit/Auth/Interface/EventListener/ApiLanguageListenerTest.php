<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\EventListener;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\EventListener\ApiLanguageListener;
use App\Shared\Application\Http\AcceptLanguageMatcher;
use App\Shared\Application\Http\RequestLocale;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use App\UserPreference\Application\Port\UserSettingView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** A request's API language is the user's saved choice, else Accept-Language, else English. */
final class ApiLanguageListenerTest extends TestCase
{
    private const string USER_ID = '01959fae-7c5b-7f00-8e00-000000000001';

    /** @return iterable<string, array{?UserSettingView, ?string, string}> */
    public static function requests(): iterable
    {
        yield 'saved choice beats the browser' => [self::language('da', 'user'), 'th', 'da'];
        yield 'no choice, supported browser language' => [self::language(null, 'server_default'), 'th-TH,th;q=0.9', 'th'];
        yield 'the server default is not a choice' => [self::language('da', 'server_default'), null, 'en'];
        yield 'a choice no longer offered is ignored' => [self::language('de', 'user', valid: false), 'da', 'da'];
        yield 'no choice, unsupported browser language' => [self::language(null, 'default'), 'fr-FR,fr;q=0.9', 'en'];
        yield 'signed out, browser language' => [null, 'da', 'da'];
        yield 'signed out, no header' => [null, null, 'en'];
    }

    #[DataProvider('requests')]
    public function testResolvesTheLanguageOnceTheFirewallHasRun(?UserSettingView $language, ?string $acceptLanguage, string $expected): void
    {
        $request = $this->request($acceptLanguage);

        $this->listener($language)->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame($expected, RequestLocale::of($request->attributes->get(RequestLocale::ATTRIBUTE)));
    }

    public function testARequestTheFirewallRefusedIsResolvedWhenTheErrorIsRendered(): void
    {
        $request = $this->request('th');

        $this->listener(self::language('da', 'user'))->onException($this->exception($request));

        self::assertSame('da', RequestLocale::of($request->attributes->get(RequestLocale::ATTRIBUTE)));
    }

    public function testAnErrorAfterResolutionKeepsTheResolvedLanguage(): void
    {
        $request = $this->request('th');
        $request->attributes->set(RequestLocale::ATTRIBUTE, 'da');

        $this->listener(null)->onException($this->exception($request));

        self::assertSame('da', $request->attributes->get(RequestLocale::ATTRIBUTE));
    }

    public function testAnErrorFromReadingTheChoiceFallsBackToTheBrowserLanguage(): void
    {
        $settings = $this->createStub(UserSettingsContractInterface::class);
        $settings->method('setting')->willThrowException(new \RuntimeException('The database is unavailable.'));
        $request = $this->request('th');

        (new ApiLanguageListener($this->signedIn(), $settings, new AcceptLanguageMatcher()))->onException($this->exception($request));

        self::assertSame('th', RequestLocale::of($request->attributes->get(RequestLocale::ATTRIBUTE)));
    }

    public function testTheSavedChoiceIsReadOnlyWhenAMessageIsTranslatedAndOnce(): void
    {
        $reads = 0;
        $settings = $this->createStub(UserSettingsContractInterface::class);
        $settings->method('setting')->willReturnCallback(static function () use (&$reads): UserSettingView {
            ++$reads;

            return self::language('da', 'user');
        });
        $request = $this->request('th');

        (new ApiLanguageListener($this->signedIn(), $settings, new AcceptLanguageMatcher()))
            ->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
        $resolver = $request->attributes->get(RequestLocale::ATTRIBUTE);
        self::assertSame(0, $reads);

        self::assertSame('da', RequestLocale::of($resolver));
        self::assertSame('da', RequestLocale::of($resolver));
        self::assertSame(1, $reads);
    }

    private static function language(?string $stored, string $source, bool $valid = true): UserSettingView
    {
        return new UserSettingView(
            key: 'language',
            label: 'Language',
            type: 'enum',
            options: [],
            userEditable: true,
            storedValue: $stored,
            storedValueValid: $valid,
            value: $stored ?? 'en',
            resetValue: 'en',
            source: $source,
        );
    }

    /** @param ?UserSettingView $language null for a signed-out request */
    private function listener(?UserSettingView $language): ApiLanguageListener
    {
        $settings = $this->createStub(UserSettingsContractInterface::class);
        if ($language === null) {
            return new ApiLanguageListener(new TokenStorage(), $settings, new AcceptLanguageMatcher());
        }

        $settings->method('setting')->willReturnCallback(static fn (string $userId, string $key): UserSettingView => $userId === self::USER_ID && $key === 'language'
            ? $language
            : throw new \LogicException(sprintf('Unexpected setting "%s" of "%s".', $key, $userId)));

        return new ApiLanguageListener($this->signedIn(), $settings, new AcceptLanguageMatcher());
    }

    private function signedIn(): TokenStorage
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new SecurityUser(self::USER_ID, 'alice@baander.app', 'hash', ['ROLE_USER']), 'api', ['ROLE_USER']));

        return $tokens;
    }

    private function request(?string $acceptLanguage): Request
    {
        return Request::create('/api/libraries', server: $acceptLanguage === null ? [] : ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage]);
    }

    private function exception(Request $request): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new AccessDeniedHttpException());
    }
}
