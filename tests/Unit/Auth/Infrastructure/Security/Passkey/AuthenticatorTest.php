<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Application\DTO\VerifiedPasswordLogin;
use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Exception\TotpSecretUnavailableException;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\OAuth\DpopTokenRequestVerifier;
use App\Auth\Infrastructure\Security\Passkey\DpopProofRejectedException;
use App\Auth\Infrastructure\Security\User\PasswordAuthenticator;
use App\Shared\Application\Http\PublicOrigins;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Auth\AuthenticationFailureMessages;
use App\Tests\Fixtures\Auth\RecordingLoginFailureThrottle;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class PasswordAuthenticatorTest extends TestCase
{
    private const string URI = 'https://baander.app/api/auth/login';

    private PasswordAuthenticator $authenticator;
    private TotpVerifierInterface&Stub $totpVerifier;
    private UserRepositoryInterface&Stub $userRepository;
    private PasswordHasherInterface&Stub $passwordHasher;
    private Redis&Stub $redis;
    private RecordingLoginFailureThrottle $throttle;

    protected function setUp(): void
    {
        $this->throttle = new RecordingLoginFailureThrottle();
        $this->totpVerifier = $this->createStub(TotpVerifierInterface::class);
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $this->redis = $this->createStub(Redis::class);
        // The proof's nonce was issued and is consumed once.
        $this->redis->method('getDel')->willReturn('1');
        $redis = $this->redis;
        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(static fn (callable $callback): mixed => $callback($redis));
        $jtiCache = $this->createStub(DpopJtiCacheInterface::class);
        $jtiCache->method('isReplay')->willReturn(false);

        $this->authenticator = new PasswordAuthenticator(
            $this->totpVerifier,
            $this->userRepository,
            $this->passwordHasher,
            $this->createStub(LoginBlockRepositoryInterface::class),
            $this->createStub(LoggerInterface::class),
            new JsonEncoder(),
            AuthenticationFailureMessages::create(),
            new DpopTokenRequestVerifier(new DpopProofValidator($jtiCache, new PublicOrigins('https://baander.app')), new DpopNonceManager($factory)),
            $this->throttle,
        );
    }

    public function testSupportsCorrectRoute(): void
    {
        $this->assertTrue($this->authenticator->supports(Request::create('/api/auth/login', 'POST')));
    }

    public function testDoesNotSupportWrongMethod(): void
    {
        $this->assertFalse($this->authenticator->supports(Request::create('/api/auth/login', 'GET')));
    }

    public function testDoesNotSupportWrongPath(): void
    {
        $this->assertFalse($this->authenticator->supports(Request::create('/api/auth/register', 'POST')));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function incompleteCredentials(): iterable
    {
        yield 'no fields' => [[]];
        yield 'no email' => [['password' => 'secret']];
        yield 'no password' => [['email' => 'alice@baander.app']];
    }

    /** @param array<string, string> $body */
    #[DataProvider('incompleteCredentials')]
    public function testIncompleteCredentialsAreInvalid(array $body): void
    {
        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->authenticator->authenticate($this->request($body));
    }

    public function testAnUnknownUserIsInvalid(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->authenticator->authenticate($this->request(['email' => 'alice@baander.app', 'password' => 'secret']));
    }

    public function testAWrongPasswordIsInvalid(): void
    {
        $this->userRepository->method('findByEmail')->willReturn($this->createUser());
        $this->passwordHasher->method('verify')->willReturn(false);

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->authenticator->authenticate($this->request(['email' => 'alice@baander.app', 'password' => 'wrong']));
    }

    /** An unknown address costs one password hash check, like a known one. */
    public function testAnUnknownUserIsCheckedAgainstADummyHash(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->passwordHasher->method('hash')->willReturn('$argon2id$dummy');
        $verified = [];
        $this->passwordHasher->method('verify')->willReturnCallback(static function (string $plain, string $hash) use (&$verified): bool {
            $verified[] = [$plain, $hash];

            return false;
        });

        $this->failure($this->request(['email' => 'alice@baander.app', 'password' => 'secret']));
        $this->failure($this->request(['email' => 'bob@baander.app', 'password' => 'other']));

        $this->assertSame([['secret', '$argon2id$dummy'], ['other', '$argon2id$dummy']], $verified);
    }

    public function testADisabledAccountWithAWrongPasswordIsInvalid(): void
    {
        $this->userRepository->method('findByEmail')->willReturn($this->createUser(disabled: true));
        $this->passwordHasher->method('verify')->willReturn(false);

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->authenticator->authenticate($this->request(['email' => 'alice@baander.app', 'password' => 'wrong']));
    }

    public function testADisabledAccountIsReportedOnlyAfterTheRightPassword(): void
    {
        $this->userRepository->method('findByEmail')->willReturn($this->createUser(disabled: true));
        $this->passwordHasher->method('verify')->willReturn(true);
        $request = $this->request(['email' => 'alice@baander.app', 'password' => 'secret']);

        $response = $this->authenticator->onAuthenticationFailure($request, $this->failure($request));

        $this->assertSame(
            ['error' => ['message' => 'This account has been disabled.', 'code' => 'AUTH_INVALID_CREDENTIALS']],
            json_decode((string) $response->getContent(), true),
        );
        $this->assertSame([], $this->throttle->failures, 'A correct password is not a failed guess.');
    }

    /** @return iterable<string, array{?bool, bool}> whether the account exists and is disabled, and whether the password matches */
    public static function rejectedAccounts(): iterable
    {
        yield 'unknown' => [null, false];
        yield 'disabled, wrong password' => [true, false];
        yield 'enabled, wrong password' => [false, false];
    }

    #[DataProvider('rejectedAccounts')]
    public function testRejectedAccountsGetTheSameAnswer(?bool $disabled, bool $passwordMatches): void
    {
        $this->userRepository->method('findByEmail')->willReturn($disabled === null ? null : $this->createUser(disabled: $disabled));
        $this->passwordHasher->method('hash')->willReturn('$argon2id$dummy');
        $this->passwordHasher->method('verify')->willReturn($passwordMatches);
        $request = $this->request(['email' => 'alice@baander.app', 'password' => 'wrong']);

        $response = $this->authenticator->onAuthenticationFailure($request, $this->failure($request));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('{"error":{"message":"Invalid credentials.","code":"AUTH_INVALID_CREDENTIALS"}}', $response->getContent());
        $this->assertSame(['alice@baander.app'], $this->throttle->failures, 'Every rejected guess is charged to the address.');
    }

    public function testARejectedCodeIsChargedToTheAddress(): void
    {
        $this->userRepository->method('findByEmail')->willReturn($this->createUser('sealed-secret'));
        $this->passwordHasher->method('verify')->willReturn(true);
        $this->totpVerifier->method('matchEnrolledStep')->willReturn(null);

        $this->failure($this->request(['email' => 'Alice@baander.app', 'password' => 'secret', 'totpCode' => '000000']));

        $this->assertSame(['alice@baander.app'], $this->throttle->failures);
    }

    public function testAskingForTheCodeIsNotCharged(): void
    {
        $this->userRepository->method('findByEmail')->willReturn($this->createUser('sealed-secret'));
        $this->passwordHasher->method('verify')->willReturn(true);

        $this->failure($this->request(['email' => 'alice@baander.app', 'password' => 'secret']));

        $this->assertSame([], $this->throttle->failures);
    }

    /** Once the address has used up its failures, even the right password waits, known account or not. */
    public function testAnAddressOverItsFailureLimitIsRefusedBeforeTheCredentialsAreChecked(): void
    {
        $this->throttle->retryAfter = 120;
        $this->userRepository->method('findByEmail')->willReturnCallback(static fn (): never => self::fail('The account must not be looked up over the limit.'));
        $this->passwordHasher->method('verify')->willReturnCallback(static fn (): never => self::fail('The password must not be checked over the limit.'));

        try {
            $this->authenticator->authenticate($this->request(['email' => 'alice@baander.app', 'password' => 'secret']));
            self::fail('The login must be refused.');
        } catch (TooManyRequestsHttpException $exception) {
            self::assertSame(['Retry-After' => 120], $exception->getHeaders());
        }
        $this->assertSame([], $this->throttle->failures);
    }

    public function testASuccessfulLoginIsNotCharged(): void
    {
        $this->userRepository->method('findByEmail')->willReturn($this->createUser());
        $this->passwordHasher->method('verify')->willReturn(true);

        $this->authenticator->authenticate($this->request(['email' => 'alice@baander.app', 'password' => 'secret']));

        $this->assertSame([], $this->throttle->failures);
    }

    public function testAPasswordSignsInAnAccountWithoutTotp(): void
    {
        $user = $this->createUser();
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->passwordHasher->method('verify')->willReturn(true);
        $request = $this->request(['email' => 'alice@baander.app', 'password' => 'secret']);

        $passport = $this->authenticator->authenticate($request);

        $this->assertSame($user->getEmail(), $passport->getUser()->getUserIdentifier());
        $login = $request->attributes->get(VerifiedPasswordLogin::class);
        $this->assertInstanceOf(VerifiedPasswordLogin::class, $login);
        $this->assertNull($login->totpStep);
    }

    /** The matched step travels to the controller, which spends it with the tokens. */
    public function testAMatchingCodeCarriesItsStepToIssuance(): void
    {
        $user = $this->createUser('sealed-secret');
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->passwordHasher->method('verify')->willReturn(true);
        $this->totpVerifier->method('matchEnrolledStep')->willReturn(58_000_001);
        $request = $this->request(['email' => 'alice@baander.app', 'password' => 'secret', 'totpCode' => '123456']);

        $this->authenticator->authenticate($request);

        $login = $request->attributes->get(VerifiedPasswordLogin::class);
        $this->assertInstanceOf(VerifiedPasswordLogin::class, $login);
        $this->assertSame(58_000_001, $login->totpStep);
        $this->assertSame($user->getId(), $login->userId);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function withoutCode(): iterable
    {
        yield 'no code' => [['email' => 'alice@baander.app', 'password' => 'secret']];
        yield 'empty code' => [['email' => 'alice@baander.app', 'password' => 'secret', 'totpCode' => '']];
    }

    /** @param array<string, string> $body */
    #[DataProvider('withoutCode')]
    public function testATotpAccountWithoutACodeIsAskedForOne(array $body): void
    {
        $this->userRepository->method('findByEmail')->willReturn($this->createUser('sealed-secret'));
        $this->passwordHasher->method('verify')->willReturn(true);

        $exception = $this->failure($this->request($body));

        $this->assertInstanceOf(CustomUserMessageAuthenticationException::class, $exception);
        $this->assertSame('AUTH_TOTP_REQUIRED', $exception->getMessageData()['error_code']);
    }

    public function testACodeThatMatchesNoStepIsInvalid(): void
    {
        $this->userRepository->method('findByEmail')->willReturn($this->createUser('sealed-secret'));
        $this->passwordHasher->method('verify')->willReturn(true);
        $this->passwordHasher->method('needsRehash')->willReturnCallback(
            static fn (): never => self::fail('Password rehashing must wait until the second factor is verified.'),
        );
        $this->totpVerifier->method('matchEnrolledStep')->willReturn(null);

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->authenticator->authenticate($this->request(['email' => 'alice@baander.app', 'password' => 'secret', 'totpCode' => '000000']));
    }

    public function testASecretThatCannotBeOpenedIsUnavailableNotOff(): void
    {
        $this->userRepository->method('findByEmail')->willReturn($this->createUser('sealed-under-another-key'));
        $this->passwordHasher->method('verify')->willReturn(true);
        $this->totpVerifier->method('matchEnrolledStep')->willThrowException(TotpSecretUnavailableException::forStoredSecret());
        $request = $this->request(['email' => 'alice@baander.app', 'password' => 'secret', 'totpCode' => '123456']);

        $response = $this->authenticator->onAuthenticationFailure($request, $this->failure($request));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            ['error' => ['message' => 'Two-factor sign-in is unavailable for this account. Ask your administrator to reset its two-factor authentication.', 'code' => 'AUTH_TOTP_UNAVAILABLE']],
            json_decode((string) $response->getContent(), true),
        );
        $this->assertFalse($request->attributes->has(VerifiedPasswordLogin::class));
    }

    /** A nonce challenge comes before the credentials, so the code stays unspent for the retry. */
    public function testAProofWithoutANonceIsChallengedBeforeTheCredentialsAreRead(): void
    {
        $this->userRepository->method('findByEmail')->willReturnCallback(static fn (): never => self::fail('Credentials must not be read before the proof is accepted.'));
        $request = $this->request(['email' => 'alice@baander.app', 'password' => 'secret', 'totpCode' => '123456'], (new SignedDpopProof())->create('POST', self::URI, 'no-access-token'));

        $exception = $this->failure($request);

        $this->assertInstanceOf(DpopProofRejectedException::class, $exception);
        $response = $this->authenticator->onAuthenticationFailure($request, $exception);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertTrue($response->headers->has('DPoP-Nonce'));
    }

    public function testAStoreOutageIsAnsweredWith503BeforeTheCredentials(): void
    {
        $this->redis->method('setex')->willThrowException(new RedisException('Connection refused'));
        $this->userRepository->method('findByEmail')->willReturnCallback(static fn (): never => self::fail('Credentials must not be read during the outage.'));

        try {
            $this->authenticator->authenticate($this->request(['email' => 'alice@baander.app', 'password' => 'secret']));
            self::fail('The outage must not be reported as an authentication failure.');
        } catch (ServiceUnavailableHttpException $exception) {
            self::assertSame(503, $exception->getStatusCode());
            self::assertEqualsCanonicalizing(['retry-after' => '5', 'cache-control' => 'no-store'], array_change_key_case(array_map(strval(...), $exception->getHeaders()), CASE_LOWER));
            self::assertInstanceOf(DpopStoreUnavailableException::class, $exception->getPrevious());
        }
    }

    public function testOnAuthenticationSuccessReturnsNull(): void
    {
        $this->assertNull($this->authenticator->onAuthenticationSuccess(Request::create('/'), $this->createStub(TokenInterface::class), 'main'));
    }

    public function testOnAuthenticationFailureReturnsStructuredError(): void
    {
        $result = $this->authenticator->onAuthenticationFailure(Request::create('/'), new BadCredentialsException('Invalid credentials'));

        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame(
            ['error' => ['message' => 'Invalid credentials.', 'code' => 'AUTH_INVALID_CREDENTIALS']],
            json_decode((string) $result->getContent(), true),
        );
    }

    public function testAWrongPasswordIsReportedInTheAcceptLanguageWithTheSameCode(): void
    {
        $this->userRepository->method('findByEmail')->willReturn($this->createUser());
        $this->passwordHasher->method('verify')->willReturn(false);
        $request = $this->request(['email' => 'alice@baander.app', 'password' => 'wrong']);
        $request->headers->set('Accept-Language', 'da-DK,da;q=0.9,en;q=0.5');

        $result = $this->authenticator->onAuthenticationFailure($request, $this->failure($request));

        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame(
            ['error' => ['message' => 'Ugyldige loginoplysninger.', 'code' => 'AUTH_INVALID_CREDENTIALS']],
            json_decode((string) $result->getContent(), true),
        );
    }

    public function testOnAuthenticationFailureReturnsTotpRequiredError(): void
    {
        $exception = new CustomUserMessageAuthenticationException(
            'TOTP code is required.',
            ['totp_required' => true, 'error_code' => 'AUTH_TOTP_REQUIRED'],
        );

        $data = json_decode((string) $this->authenticator->onAuthenticationFailure(Request::create('/'), $exception)->getContent(), true);

        $this->assertSame('AUTH_TOTP_REQUIRED', $data['error']['code']);
        $this->assertSame('TOTP code is required.', $data['error']['message']);
        $this->assertTrue($data['error']['details']['totp_required']);
    }

    /** @param array<string, string> $body */
    private function request(array $body, ?string $proof = null): Request
    {
        $request = Request::create(self::URI, 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('DPoP', $proof ?? (new SignedDpopProof())->createWithNonce('POST', self::URI, 'issued-nonce'));

        return $request;
    }

    private function failure(Request $request): AuthenticationException
    {
        try {
            $this->authenticator->authenticate($request);
        } catch (AuthenticationException $exception) {
            return $exception;
        }
        self::fail('The login must not authenticate.');
    }

    private function createUser(?string $totpSecretCiphertext = null, bool $disabled = false): User
    {
        return User::reconstitute(new UserState(
            id: Uuid::fromString('01959fae-7c5b-7f00-8e00-000000000001'),
            publicId: new PublicId(),
            name: 'Alice',
            email: 'alice@baander.app',
            password: '$hashed$',
            totpSecretCiphertext: $totpSecretCiphertext,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            disabled: $disabled,
        ));
    }
}
