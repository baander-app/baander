<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\User\PasswordAuthenticator;
use App\Auth\Infrastructure\Security\Passkey\PasskeyAuthenticator;
use App\Auth\Infrastructure\Security\Totp\TotpService;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class PasswordAuthenticatorTest extends TestCase
{
    private PasswordAuthenticator $authenticator;
    private TotpService $totpService;
    private UserRepositoryInterface&Stub $userRepository;
    private PasswordHasherInterface&Stub $passwordHasher;
    private LoggerInterface&Stub $logger;

    protected function setUp(): void
    {
        $this->totpService = new TotpService(issuer: 'Test', window: 0);
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->authenticator = new PasswordAuthenticator(
            $this->totpService,
            $this->userRepository,
            $this->passwordHasher,
            $this->createStub(LoginBlockRepositoryInterface::class),
            $this->logger,
            new JsonEncoder(),
        );
    }

    public function testSupportsCorrectRoute(): void
    {
        $request = Request::create('/api/auth/login', 'POST');

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testDoesNotSupportWrongMethod(): void
    {
        $request = Request::create('/api/auth/login', 'GET');

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testDoesNotSupportWrongPath(): void
    {
        $request = Request::create('/api/auth/register', 'POST');

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testAuthenticateThrowsOnMissingFields(): void
    {
        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->expectException(\Symfony\Component\Security\Core\Exception\BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsOnMissingEmail(): void
    {
        $payload = json_encode(['password' => 'secret']);
        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

        $this->expectException(\Symfony\Component\Security\Core\Exception\BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsOnMissingPassword(): void
    {
        $payload = json_encode(['email' => 'alice@baander.app']);
        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

        $this->expectException(\Symfony\Component\Security\Core\Exception\BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsOnUserNotFound(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);

        $payload = json_encode(['email' => 'alice@baander.app', 'password' => 'secret']);
        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

        $this->expectException(\Symfony\Component\Security\Core\Exception\BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsOnInvalidPassword(): void
    {
        $user = $this->createUser(totpSecret: null);
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->passwordHasher->method('verify')->willReturn(false);

        $payload = json_encode(['email' => 'alice@baander.app', 'password' => 'wrong']);
        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

        $this->expectException(\Symfony\Component\Security\Core\Exception\BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateSucceedsWithPasswordOnlyForUserWithoutTotp(): void
    {
        $user = $this->createUser(totpSecret: null);
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->passwordHasher->method('verify')->willReturn(true);

        $payload = json_encode(['email' => 'alice@baander.app', 'password' => 'secret']);
        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

        $passport = $this->authenticator->authenticate($request);

        $this->assertSame($user->getEmail(), $passport->getUser()->getUserIdentifier());
    }

    public function testAuthenticateSucceedsWithValidTotpCode(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = $this->createUser(totpSecret: $secret);
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->passwordHasher->method('verify')->willReturn(true);

        // Generate a real TOTP code that will pass verification
        $totp = \OTPHP\TOTP::create($secret);
        $validCode = $totp->now();

        $payload = json_encode(['email' => 'alice@baander.app', 'password' => 'secret', 'totpCode' => $validCode]);
        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

        $passport = $this->authenticator->authenticate($request);

        $this->assertSame($user->getEmail(), $passport->getUser()->getUserIdentifier());
    }

    public function testAuthenticateThrowsTotpRequiredWhenTotpEnabledAndNoCode(): void
    {
        $user = $this->createUser(totpSecret: 'JBSWY3DPEHPK3PXP');
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->passwordHasher->method('verify')->willReturn(true);

        $payload = json_encode(['email' => 'alice@baander.app', 'password' => 'secret']);
        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

        $this->expectException(\Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('TOTP code is required.');

        try {
            $this->authenticator->authenticate($request);
        } catch (\Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException $e) {
            $this->assertTrue($e->getMessageData()['totp_required'] ?? false);
            $this->assertSame('AUTH_TOTP_REQUIRED', $e->getMessageData()['error_code']);

            throw $e;
        }
    }

    public function testAuthenticateThrowsInvalidCredentialsOnInvalidTotpCode(): void
    {
        $user = $this->createUser(totpSecret: 'JBSWY3DPEHPK3PXP');
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->passwordHasher->method('verify')->willReturn(true);

        // '000000' will not match any valid TOTP code with window=0
        $payload = json_encode(['email' => 'alice@baander.app', 'password' => 'secret', 'totpCode' => '000000']);
        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

        $this->expectException(\Symfony\Component\Security\Core\Exception\BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsInvalidCredentialsOnEmptyTotpCode(): void
    {
        $user = $this->createUser(totpSecret: 'JBSWY3DPEHPK3PXP');
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->passwordHasher->method('verify')->willReturn(true);

        $payload = json_encode(['email' => 'alice@baander.app', 'password' => 'secret', 'totpCode' => '']);
        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

        $this->expectException(\Symfony\Component\Security\Core\Exception\BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->authenticator->authenticate($request);
    }

    public function testOnAuthenticationSuccessReturnsNull(): void
    {
        $result = $this->authenticator->onAuthenticationSuccess(
            Request::create('/'),
            $this->createStub(TokenInterface::class),
            'main',
        );

        $this->assertNull($result);
    }

    public function testOnAuthenticationFailureReturnsStructuredError(): void
    {
        $result = $this->authenticator->onAuthenticationFailure(
            Request::create('/'),
            new \Symfony\Component\Security\Core\Exception\BadCredentialsException('Invalid credentials'),
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

    public function testOnAuthenticationFailureReturnsTotpRequiredError(): void
    {
        $exception = new \Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException(
            'TOTP code is required.',
            ['totp_required' => true, 'error_code' => 'AUTH_TOTP_REQUIRED'],
        );

        $result = $this->authenticator->onAuthenticationFailure(
            Request::create('/'),
            $exception,
        );

        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertSame(401, $result->getStatusCode());

        $data = json_decode((string) $result->getContent(), true);
        $this->assertSame('AUTH_TOTP_REQUIRED', $data['error']['code']);
        $this->assertSame('TOTP code is required.', $data['error']['message']);
        $this->assertTrue($data['error']['details']['totp_required']);
    }

    private function createUser(?string $totpSecret = null): User
    {
        return User::reconstitute(new \App\Auth\Domain\Model\UserState(
            id: Uuid::fromString('01959fae-7c5b-7f00-8e00-000000000001'),
            publicId: new PublicId(),
            name: 'Alice',
            email: 'alice@baander.app',
            password: '$hashed$',
            totpSecret: $totpSecret,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));
    }
}
