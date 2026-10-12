<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopBindingListener;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RedisException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class DpopBindingListenerTest extends TestCase
{
    #[DataProvider('protectedPaths')]
    public function testAuthenticatedUserCannotBypassProofValidation(string $path): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new SecurityUser('user', 'user@example.test', ''), 'api', ['ROLE_USER']));
        $listener = new DpopBindingListener(
            $storage,
            new DpopProofValidator($this->createStub(DpopJtiCacheInterface::class)),
            new NullLogger(),
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

    /** R7: a jti store that cannot answer is not a verdict on the proof, so the request is told to wait, not refused. */
    public function testAStoreOutageAnswers503InsteadOfDenyingAccess(): void
    {
        $jtiCache = $this->createStub(DpopJtiCacheInterface::class);
        $jtiCache->method('isReplay')->willThrowException(DpopStoreUnavailableException::jtiStore(new RedisException('Connection refused')));
        $event = $this->boundRequest($jtiCache);

        $this->listener($jtiCache)->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response, 'The outage is answered at the listener.');
        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('DPoP-Nonce'));
        self::assertFalse($response->headers->has('WWW-Authenticate'));
        self::assertSame(503, json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }

    public function testARealReplayIsStillDenied(): void
    {
        $jtiCache = $this->createStub(DpopJtiCacheInterface::class);
        $jtiCache->method('isReplay')->willReturn(true);
        $event = $this->boundRequest($jtiCache);

        $this->expectException(AccessDeniedException::class);
        $this->listener($jtiCache)->onKernelRequest($event);
    }

    private function listener(DpopJtiCacheInterface $jtiCache): DpopBindingListener
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new SecurityUser('user', 'listener@baander.app', ''), 'api', ['ROLE_USER']));

        return new DpopBindingListener($storage, new DpopProofValidator($jtiCache), new NullLogger());
    }

    /** A GET with a DPoP-bound access token and a proof signed by the bound key. */
    private function boundRequest(DpopJtiCacheInterface $jtiCache): RequestEvent
    {
        $key = new SignedDpopProof();
        $accessToken = SignedDpopProof::encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)) . '.'
            . SignedDpopProof::encode(json_encode(['sub' => 'user', 'cnf' => ['jkt' => $key->thumbprint()]], JSON_THROW_ON_ERROR)) . '.'
            . SignedDpopProof::encode(random_bytes(32));
        $request = Request::create('https://baander.app/api/auth/me', 'GET');
        $request->headers->set('Authorization', 'DPoP ' . $accessToken);
        $request->headers->set('DPoP', $key->create('GET', 'https://baander.app/api/auth/me', $accessToken));

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
