<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller;

use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Controller\User\LoginController;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Password login while the DPoP nonce store is unavailable.
 *
 * The outage answers 503 with Retry-After and no nonce before any token is issued;
 * a login that issued its tokens before the nonce store failed still answers 200,
 * without the DPoP-Nonce header.
 */
final class LoginControllerOutageTest extends TestCase
{
    private const string URI = 'https://baander.app/api/auth/login';

    private MessageBusInterface&MockObject $bus;
    private Redis&Stub $redis;
    private LoginController $controller;

    protected function setUp(): void
    {
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->redis = $this->createStub(Redis::class);
        $redis = $this->redis;
        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(static fn (callable $callback): mixed => $callback($redis));
        $jtiCache = $this->createStub(DpopJtiCacheInterface::class);
        $jtiCache->method('isReplay')->willReturn(false);

        $user = User::register(new Email('listener@baander.app'), 'stored-password-hash', 'Listener');
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($user->getId()->toString(), $user->getEmail(), 'stored-password-hash'));
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturn($user);
        $spaClientId = (new PublicId())->toString();
        $clients = $this->createStub(ClientRepositoryInterface::class);
        $clients->method('findClientByPublicId')->willReturn(Client::registerPublic('Baander web', ['https://baander.app/callback']));

        $this->controller = new LoginController(
            $security,
            $this->bus,
            $clients,
            $users,
            new DpopProofValidator($jtiCache),
            new DpopNonceManager($factory),
            $spaClientId,
        );
    }

    public function testALoginDuringAStoreOutageAnswers503WithoutANonceAndIssuesNothing(): void
    {
        $this->redis->method('getDel')->willThrowException(new RedisException('Connection refused'));
        $this->bus->expects($this->never())->method('dispatch');

        $response = $this->login((new SignedDpopProof())->createWithNonce('POST', self::URI, 'issued-nonce'));

        self::assertStoreUnavailable($response);
    }

    public function testNoChallengeIsIssuedWhenItsNonceCannotBeStored(): void
    {
        $this->redis->method('setex')->willThrowException(new RedisException('read error on connection'));
        $this->bus->expects($this->never())->method('dispatch');

        $response = $this->login((new SignedDpopProof())->create('POST', self::URI, 'no-access-token'));

        self::assertStoreUnavailable($response);
    }

    public function testALoginWhosePostIssuanceNonceStoreFailsAnswers200WithoutTheHeader(): void
    {
        $this->redis->method('getDel')->willReturn('1');
        $this->redis->method('setex')->willThrowException(new RedisException('Connection refused'));
        $this->bus->expects($this->once())->method('dispatch')->willReturnCallback(
            static fn (object $command): Envelope => new Envelope($command, [new HandledStamp(new TokenResponseDTO('access', refreshToken: 'issued'), 'handler')]),
        );

        $response = $this->login((new SignedDpopProof())->createWithNonce('POST', self::URI, 'issued-nonce'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('issued', $body['data']['refreshToken']);
        self::assertSame('listener@baander.app', $body['data']['user']['email']);
        self::assertFalse($response->headers->has('DPoP-Nonce'));
    }

    private static function assertStoreUnavailable(JsonResponse $response): void
    {
        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('DPoP-Nonce'));
        self::assertFalse($response->headers->has('WWW-Authenticate'));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(503, $body['error']['code']);
        self::assertArrayNotHasKey('data', $body);
    }

    private function login(string $proof): JsonResponse
    {
        $request = Request::create(self::URI, 'POST', server: ['REMOTE_ADDR' => '10.0.0.7', 'CONTENT_TYPE' => 'application/json'], content: '{"email":"listener@baander.app","password":"secret"}');
        $request->headers->set('DPoP', $proof);

        return $this->controller->__invoke($request);
    }
}
