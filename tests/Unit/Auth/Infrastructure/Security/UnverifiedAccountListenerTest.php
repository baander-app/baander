<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Application\Http\AccessTokenAttributes;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Infrastructure\Security\UnverifiedAccountListener;
use App\Shared\Application\Http\AcceptLanguageMatcher;
use App\Shared\Domain\Model\Setting\SupportedLanguages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Translation\Formatter\MessageFormatter;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class UnverifiedAccountListenerTest extends TestCase
{
    /** @return iterable<string, array{string, bool|null, bool}> route, verified marker (null: absent), allowed */
    public static function requests(): iterable
    {
        yield 'verified account on a playlist read' => ['playlist_index', true, true];
        yield 'verified account on a WebSocket ticket' => ['auth_websocket_ticket', true, true];
        yield 'unverified account on a playlist read' => ['playlist_index', false, false];
        yield 'unverified account on a WebSocket ticket' => ['auth_websocket_ticket', false, false];
        yield 'unverified account on a profile update' => ['auth_me_update', false, false];
        yield 'unverified account on a route no list names' => ['unclassified_route', false, false];
        yield 'request without the marker on a playlist read' => ['playlist_index', null, false];
        yield 'unverified account reading its profile' => ['auth_me', false, true];
        yield 'unverified account opening its verification link' => ['auth_email_verify', false, true];
        yield 'unverified account resending its link' => ['auth_me_email_verification', false, true];
        yield 'unverified account changing its address' => ['auth_me_email', false, true];
        yield 'unverified account refreshing its session' => ['auth_refresh', false, true];
        yield 'unverified account signing out' => ['auth_logout', false, true];
        yield 'unverified account on a public route' => ['auth_registration', false, true];
    }

    #[DataProvider('requests')]
    public function testAnUnverifiedAccountReachesOnlyTheRoutesThatConfirmItsAddress(string $route, ?bool $verified, bool $allowed): void
    {
        $request = Request::create('/api/anything');
        $request->attributes->set('_route', $route);
        if ($verified !== null) {
            $request->attributes->set(AccessTokenAttributes::EMAIL_VERIFIED, $verified);
        }

        $event = $this->dispatch($request);

        self::assertSame(!$allowed, $event->getResponse() !== null);
    }

    public function testTheRefusalIsTheErrorEnvelopeWithTheReasonInTheAcceptedLanguage(): void
    {
        $request = Request::create('/api/playlists/');
        $request->attributes->set('_route', 'playlist_index');
        $request->attributes->set(AccessTokenAttributes::EMAIL_VERIFIED, false);
        $request->headers->set('Accept-Language', 'da-DK,da;q=0.9');

        $response = $this->dispatch($request)->getResponse();

        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            ['error' => ['message' => 'Bekræft din e-mailadresse først.', 'code' => 403, 'details' => ['reason' => 'email_unverified']]],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testARefusalTheScopeListenerSetIsKept(): void
    {
        $request = Request::create('/api/playlists/');
        $request->attributes->set('_route', 'playlist_index');
        $insufficientScope = new JsonResponse(['error' => ['code' => 'insufficient_scope']], 403);

        $event = $this->dispatch($request, $insufficientScope);

        self::assertSame($insufficientScope, $event->getResponse());
    }

    private function dispatch(Request $request, ?JsonResponse $response = null): LoginSuccessEvent
    {
        $user = new SecurityUser('0190a6a8-0000-7000-8000-000000000001', 'member@baander.app', 'hashed-pw', ['ROLE_USER']);
        $event = new LoginSuccessEvent(
            $this->createStub(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): SecurityUser => $user)),
            new PostAuthenticationToken($user, 'api', $user->getRoles()),
            $request,
            $response,
            'api',
        );
        new UnverifiedAccountListener(self::translator(), new AcceptLanguageMatcher())->onLoginSuccess($event);

        return $event;
    }

    private static function translator(): Translator
    {
        $translator = new Translator(SupportedLanguages::FALLBACK, new MessageFormatter());
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (SupportedLanguages::codes() as $locale) {
            $translator->addResource('yaml', sprintf('%s/translations/auth+intl-icu.%s.yaml', dirname(__DIR__, 5), $locale), $locale, 'auth+intl-icu');
        }

        return $translator;
    }
}
