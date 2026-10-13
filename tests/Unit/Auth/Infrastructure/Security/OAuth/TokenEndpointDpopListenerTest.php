<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\DTO\VerifiedDpopProof;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\OAuth\DpopTokenRequestVerifier;
use App\Auth\Infrastructure\Security\OAuth\TokenEndpointDpopListener;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The OAuth token endpoint's proof listener while the DPoP store is unavailable.
 *
 * The outage answers 503 with Retry-After and no nonce before any grant runs; a
 * genuine nonce miss keeps its use_dpop_nonce challenge with a fresh nonce.
 */
final class TokenEndpointDpopListenerTest extends TestCase
{
    private const string URI = 'https://baander.app/api/oauth/token';

    private Redis&Stub $redis;
    private TokenEndpointDpopListener $listener;

    protected function setUp(): void
    {
        $this->redis = $this->createStub(Redis::class);
        $redis = $this->redis;
        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(static fn (callable $callback): mixed => $callback($redis));
        $jtiCache = $this->createStub(DpopJtiCacheInterface::class);
        $jtiCache->method('isReplay')->willReturn(false);

        $this->listener = new TokenEndpointDpopListener(
            new DpopTokenRequestVerifier(new DpopProofValidator($jtiCache), new DpopNonceManager($factory)),
        );
    }

    public function testAStoreOutageAnswers503WithoutANonceBeforeTheGrantRuns(): void
    {
        $this->redis->method('getDel')->willThrowException(new RedisException('Connection refused'));

        $event = $this->request((new SignedDpopProof())->createWithNonce('POST', self::URI, 'issued-nonce'));
        $this->listener->onKernelRequest($event);

        self::assertStoreUnavailable($event);
    }

    public function testNoChallengeIsIssuedWhenItsNonceCannotBeStored(): void
    {
        $this->redis->method('setex')->willThrowException(new RedisException('read error on connection'));

        $event = $this->request((new SignedDpopProof())->create('POST', self::URI, 'no-access-token'));
        $this->listener->onKernelRequest($event);

        self::assertStoreUnavailable($event);
    }

    public function testAValidProofWhoseNextNonceCannotBeStoredIsAnsweredBeforeTheGrant(): void
    {
        $this->redis->method('getDel')->willReturn('1');
        $this->redis->method('setex')->willThrowException(new RedisException('Connection refused'));

        $event = $this->request((new SignedDpopProof())->createWithNonce('POST', self::URI, 'issued-nonce'));
        $this->listener->onKernelRequest($event);

        self::assertStoreUnavailable($event);
    }

    public function testAGenuineNonceMissKeepsItsChallenge(): void
    {
        $this->redis->method('getDel')->willReturn(false);
        $this->redis->method('setex')->willReturn(true);

        $event = $this->request((new SignedDpopProof())->createWithNonce('POST', self::URI, 'stale-nonce'));
        $this->listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('use_dpop_nonce', json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']);
        self::assertIsString($response->headers->get('DPoP-Nonce'));
    }

    public function testAValidProofIsStoredOnTheRequestWithItsNextNonce(): void
    {
        $this->redis->method('getDel')->willReturn('1');
        $this->redis->method('setex')->willReturn(true);
        $key = new SignedDpopProof();

        $event = $this->request($key->createWithNonce('POST', self::URI, 'issued-nonce'));
        $this->listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
        $proof = $event->getRequest()->attributes->get(VerifiedDpopProof::class);
        self::assertInstanceOf(VerifiedDpopProof::class, $proof);
        self::assertSame($key->thumbprint(), $proof->jkt);
        self::assertNotSame('', $proof->nextNonce);
    }

    private static function assertStoreUnavailable(RequestEvent $event): void
    {
        $response = $event->getResponse();
        self::assertNotNull($response, 'The outage is answered at the listener.');
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('DPoP-Nonce'));
        self::assertFalse($response->headers->has('WWW-Authenticate'));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(503, $body['error']['code']);
        self::assertIsString($body['error']['message']);
        self::assertFalse($event->getRequest()->attributes->has(VerifiedDpopProof::class));
    }

    private function request(string $proof): RequestEvent
    {
        $request = Request::create(self::URI, 'POST', ['grant_type' => 'refresh_token', 'client_id' => 'client'], server: ['REMOTE_ADDR' => '10.0.0.7']);
        $request->attributes->set('_route', TokenEndpointDpopListener::ROUTE);
        $request->headers->set('DPoP', $proof);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
