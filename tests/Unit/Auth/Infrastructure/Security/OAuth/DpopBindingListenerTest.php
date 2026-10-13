<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopBindingListener;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RedisException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

final class DpopBindingListenerTest extends TestCase
{
    private const string USER_ID = '0192f6c8-5b4e-7c3a-9d2e-1f0a8b7c6d5e';

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];
    private SignedDpopProof $key;
    private string $accessToken;
    private string $proof;

    #[DataProvider('protectedPaths')]
    public function testAuthenticatedUserCannotBypassProofValidation(string $path): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new SecurityUser('user', 'user@example.test', ''), 'api', ['ROLE_USER']));
        $listener = new DpopBindingListener(
            $storage,
            new DpopProofValidator($this->createStub(DpopJtiCacheInterface::class)),
            $this->logger(),
        );
        $request = Request::create($path);
        $request->headers->set('Authorization', 'Bearer invalid-token');

        $this->expectException(AccessDeniedException::class);
        $listener->onKernelRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
    }

    /** @return iterable<array{string}> */
    public static function protectedPaths(): iterable
    {
        yield ['/api/catalog/songs'];
        yield ['/api/transcode/sessions/example'];
        yield ['/api/transcode/jobs/cleanup'];
        yield ['/api/transcode/example/quality-ladder'];
    }

    /** On any other route a jti store that cannot answer is not a verdict on the proof, so the request is told to wait, not refused. */
    public function testAStoreOutageAnswers503InsteadOfDenyingAccess(): void
    {
        $event = $this->boundRequest();

        $this->listener($this->refusedStore(new RedisException('Connection refused')))->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response, 'The outage is answered at the listener.');
        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('DPoP-Nonce'));
        self::assertFalse($response->headers->has('WWW-Authenticate'));
        self::assertSame(503, json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        self::assertSame([], $this->warnings(), 'A 503 is not an accepted proof, so nothing is logged at the listener.');
    }

    public function testARealReplayIsStillDenied(): void
    {
        $jtiCache = $this->createStub(DpopJtiCacheInterface::class);
        $jtiCache->method('isReplay')->willReturn(true);
        $event = $this->boundRequest();

        $this->expectException(AccessDeniedException::class);
        $this->listener($jtiCache)->onKernelRequest($event);
    }

    /** @return iterable<string, array{string, string, Throwable}> */
    public static function revocationRoutesDuringAnOutage(): iterable
    {
        yield 'logout, refused Redis' => ['auth_logout', '/api/auth/logout', new RedisException('Connection refused')];
        yield 'logout, exhausted pool' => ['auth_logout', '/api/auth/logout', new RedisPoolExhaustedException(16, 16)];
        yield 'revoke, refused Redis' => ['oauth_revoke', '/api/oauth/revoke', new RedisException('Connection refused')];
    }

    /**
     * A logout or a revoke during the outage reaches the controller, and one warning
     * names the route, the key thumbprint and the user; no token and no proof is logged.
     */
    #[DataProvider('revocationRoutesDuringAnOutage')]
    public function testARevocationRouteDuringTheOutageReachesTheControllerWithOneWarning(string $route, string $path, Throwable $cause): void
    {
        $event = $this->boundRequest('POST', $path, $route);

        $this->listener($this->refusedStore($cause))->onKernelRequest($event);

        self::assertNull($event->getResponse(), 'The request goes on to the controller.');
        $warnings = $this->warnings();
        self::assertCount(1, $warnings, json_encode($this->logs, JSON_THROW_ON_ERROR));
        $context = $warnings[0]['context'];
        self::assertSame($route, $context['route'] ?? null);
        self::assertSame($this->key->thumbprint(), $context['jkt'] ?? null);
        self::assertSame(self::USER_ID, $context['user_id'] ?? null);
        $serialized = json_encode($this->logs, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($this->accessToken, $serialized, 'A log never carries the access token.');
        self::assertStringNotContainsString($this->proof, $serialized, 'A log never carries the proof.');
    }

    /** The two routes keep the replay verdict of a working store. */
    public function testARevocationRouteStillRefusesAReplayDetectedByAWorkingStore(): void
    {
        $jtiCache = $this->createStub(DpopJtiCacheInterface::class);
        $jtiCache->method('isReplay')->willReturn(true);
        $event = $this->boundRequest('POST', '/api/auth/logout', 'auth_logout');

        try {
            $this->listener($jtiCache)->onKernelRequest($event);
            self::fail('The replay is refused.');
        } catch (AccessDeniedException $exception) {
            self::assertSame('DPoP proof "jti" has been reused.', $exception->getMessage());
        }
        self::assertSame([], array_filter($this->warnings(), static fn (array $record): bool => isset($record['context']['route'])), 'A refused proof is not an accepted one.');
    }

    /** On the degraded path the proof must still hash the presented token. */
    public function testARevocationRouteDuringTheOutageStillRefusesAProofForAnotherToken(): void
    {
        $event = $this->boundRequest('POST', '/api/auth/logout', 'auth_logout', proofFor: 'another-access-token');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('DPoP proof "ath" does not match access token.');
        $this->listener($this->refusedStore(new RedisException('Connection refused')))->onKernelRequest($event);
    }

    private function refusedStore(Throwable $cause): DpopJtiCacheInterface
    {
        $jtiCache = $this->createStub(DpopJtiCacheInterface::class);
        $jtiCache->method('isReplay')->willThrowException(DpopStoreUnavailableException::jtiStore($cause));

        return $jtiCache;
    }

    private function listener(DpopJtiCacheInterface $jtiCache): DpopBindingListener
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new SecurityUser(self::USER_ID, 'listener@baander.app', ''), 'api', ['ROLE_USER']));

        return new DpopBindingListener($storage, new DpopProofValidator($jtiCache), $this->logger());
    }

    /**
     * A request with a DPoP-bound access token and a proof signed by the bound key, on the
     * given route; the proof hashes the presented token unless another is named.
     */
    private function boundRequest(string $method = 'GET', string $path = '/api/auth/me', string $route = 'auth_me', ?string $proofFor = null): RequestEvent
    {
        $this->key = new SignedDpopProof();
        $this->accessToken = SignedDpopProof::encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)) . '.'
            . SignedDpopProof::encode(json_encode(['sub' => self::USER_ID, 'cnf' => ['jkt' => $this->key->thumbprint()]], JSON_THROW_ON_ERROR)) . '.'
            . SignedDpopProof::encode(random_bytes(32));
        $uri = 'https://baander.app' . $path;
        $this->proof = $this->key->create($method, $uri, $proofFor ?? $this->accessToken);
        $request = Request::create($uri, $method);
        $request->attributes->set('_route', $route);
        $request->headers->set('Authorization', 'DPoP ' . $this->accessToken);
        $request->headers->set('DPoP', $this->proof);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function logger(): LoggerInterface
    {
        $record = function (string $level, string $message, array $context): void {
            $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
        };

        return new class ($record) extends AbstractLogger {
            public function __construct(private readonly \Closure $record)
            {
            }

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->record)((string) $level, (string) $message, $context);
            }
        };
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    private function warnings(): array
    {
        return array_values(array_filter($this->logs, static fn (array $record): bool => $record['level'] === 'warning'));
    }
}
