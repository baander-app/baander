<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\User\PasswordAuthenticator;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\OAuth\DpopTokenRequestVerifier;
use App\Auth\Infrastructure\Security\Passkey\PasskeyAuthenticator;
use App\Auth\Infrastructure\Security\Totp\TotpService;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Auth\AuthenticationFailureMessages;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class PasskeyAuthenticatorTest extends TestCase
{
    private PasskeyAuthenticator $authenticator;

    protected function setUp(): void
    {
        $bus = $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $redis = $this->createStub(RedisClientFactory::class);
        $redis->method('borrow')->willReturn(true);
        $verifier = new DpopTokenRequestVerifier(new DpopProofValidator($this->createStub(DpopJtiCacheInterface::class)), new DpopNonceManager($redis));
        $this->authenticator = new PasskeyAuthenticator($bus, $this->createStub(\App\Auth\Domain\Repository\UserRepositoryInterface::class), $logger, new JsonEncoder(), $verifier, AuthenticationFailureMessages::create());
    }

    public function testSupportsCorrectRoute(): void
    {
        $request = Request::create('/api/auth/login/passkey', 'POST');

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testDoesNotSupportWrongPath(): void
    {
        $request = Request::create('/api/auth/login', 'POST');

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testAuthenticateThrowsOnMissingFields(): void
    {
        $request = Request::create('https://baander.app/api/auth/login/passkey', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $request->headers->set('DPoP', (new SignedDpopProof())->createWithNonce('POST', 'https://baander.app/api/auth/login/passkey', 'issued-nonce'));

        $this->expectException(\Symfony\Component\Security\Core\Exception\BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->authenticator->authenticate($request);
    }

    public function testOnAuthenticationSuccessReturnsNull(): void
    {
        $result = $this->authenticator->onAuthenticationSuccess(
            Request::create('/'),
            $this->createStub(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class),
            'main',
        );

        $this->assertNull($result);
    }

    public function testOnAuthenticationFailureReturnsStructuredError(): void
    {
        $result = $this->authenticator->onAuthenticationFailure(
            Request::create('/'),
            new \Symfony\Component\Security\Core\Exception\BadCredentialsException('Invalid credentials.'),
        );

        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertSame(401, $result->getStatusCode());

        $data = json_decode((string) $result->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('message', $data['error']);
        $this->assertArrayHasKey('code', $data['error']);
        $this->assertSame('AUTH_INVALID_CREDENTIALS', $data['error']['code']);
        $this->assertSame('Invalid credentials.', $data['error']['message']);
    }
}
