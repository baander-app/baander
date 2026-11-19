<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Http\AccessTokenAttributes;
use App\Auth\Infrastructure\Security\OAuth\ScopeEnforcementListener;
use App\Auth\Infrastructure\Security\SecurityUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class ScopeEnforcementListenerTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>|null, bool}> route, delegated scopes (null: first-party), allowed */
    public static function requests(): iterable
    {
        yield 'first-party token on an admin route' => ['admin_users_list', null, true];
        yield 'first-party token on a route no list names' => ['unclassified_route', null, true];
        yield 'delegated token on a public route' => ['auth_refresh', [], true];
        yield 'delegated library token on a catalog read' => ['album_show', ['library'], true];
        yield 'delegated token holding one of several scopes' => ['auth_me', ['email'], true];
        yield 'delegated library token on a playlist' => ['playlist_show', ['library'], false];
        yield 'delegated token on a first-party route' => ['auth_websocket_ticket', ['library', 'playlist', 'profile', 'email'], false];
        yield 'delegated token on an admin route' => ['admin_users_list', ['library'], false];
        yield 'delegated token on a route no list names' => ['unclassified_route', ['library'], false];
    }

    /** @param list<string>|null $scopes */
    #[DataProvider('requests')]
    public function testADelegatedTokenReachesOnlyPublicRoutesAndThoseItsScopesCover(string $route, ?array $scopes, bool $allowed): void
    {
        $request = Request::create('/api/anything');
        $request->attributes->set('_route', $route);
        $request->attributes->set(AccessTokenAttributes::FIRST_PARTY, $scopes === null);
        $request->attributes->set(AccessTokenAttributes::SCOPES, $scopes ?? []);

        $event = $this->dispatch($request);

        self::assertSame(!$allowed, $event->getResponse() !== null);
    }

    public function testTheRefusalNamesTheMissingScope(): void
    {
        $request = Request::create('/api/admin/users');
        $request->attributes->set('_route', 'admin_users_list');
        $request->attributes->set(AccessTokenAttributes::FIRST_PARTY, false);
        $request->attributes->set(AccessTokenAttributes::SCOPES, ['profile']);

        $response = $this->dispatch($request)->getResponse();

        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('DPoP error="insufficient_scope"', $response->headers->get('WWW-Authenticate'));
        self::assertSame(
            ['error' => ['message' => 'The access token does not grant access to this resource.', 'code' => 'insufficient_scope']],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testAnAuthenticatedRequestWithoutTheFirstPartyMarkerIsDelegatedWithNoScopes(): void
    {
        $request = Request::create('/api/albums/');
        $request->attributes->set('_route', 'album_index');

        self::assertNotNull($this->dispatch($request)->getResponse());
    }

    private function dispatch(Request $request): LoginSuccessEvent
    {
        $user = new SecurityUser('0190a6a8-0000-7000-8000-000000000001', 'member@baander.app', 'hashed-pw', ['ROLE_USER']);
        $event = new LoginSuccessEvent(
            $this->createStub(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): SecurityUser => $user)),
            new PostAuthenticationToken($user, 'api', $user->getRoles()),
            $request,
            null,
            'api',
        );
        (new ScopeEnforcementListener())->onLoginSuccess($event);

        return $event;
    }
}
