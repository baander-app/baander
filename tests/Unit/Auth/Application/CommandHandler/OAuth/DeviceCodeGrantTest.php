<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\ExchangeDeviceCodeCommand;
use App\Auth\Application\Command\OAuth\RequestDeviceAuthorizationCommand;
use App\Auth\Application\CommandHandler\OAuth\ExchangeDeviceCodeHandler;
use App\Auth\Application\CommandHandler\OAuth\RequestDeviceAuthorizationHandler;
use App\Auth\Application\DTO\DeviceAuthorizationDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\DeviceCodeState;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\TestCase;

/** The device authorization grant from the device's side (RFC 8628 sections 3.1, 3.4 and 3.5). */
final class DeviceCodeGrantTest extends TestCase
{
    use IssuesTokenPairs;

    private const string JKT = 'tv-proof-key-thumbprint';
    private const string VERIFY = 'https://baander.app/device';

    /** @var array<string, DeviceCode> */
    private array $codes = [];
    /** @var array<string, true> */
    private array $redeemed = [];
    private Client $tv;
    private User $user;

    protected function setUp(): void
    {
        $this->tv = $this->register(Client::create('Living room TV', [], deviceClient: true));
        $this->user = User::register(new Email('viewer@baander.app'), 'hashed', 'Viewer');
    }

    public function testAuthorizationReturnsCodesAndTheAbsoluteVerificationUri(): void
    {
        $authorization = $this->authorize();

        self::assertMatchesRegularExpression('/^[BCDFGHJKLMNPQRSTVWXZ]{4}-[BCDFGHJKLMNPQRSTVWXZ]{4}$/', $authorization->userCode);
        self::assertSame(self::VERIFY, $authorization->verificationUri);
        self::assertSame(self::VERIFY . '?user_code=' . rawurlencode($authorization->userCode), $authorization->verificationUriComplete);
        self::assertSame(900, $authorization->expiresIn);
        self::assertSame(5, $authorization->interval);
        self::assertSame(['library'], $this->codes[$authorization->deviceCode]->getScopeIdentifiers());
    }

    public function testOnlyDeviceClientsMayStartTheFlow(): void
    {
        $web = $this->register(Client::create('Web app', ['https://app.baander.app/callback']));

        $this->assertProtocolError('unauthorized_client', fn () => $this->authorize($web->getPublicId()->toString()));
        $this->assertProtocolError('invalid_client', fn () => $this->authorize('unknown_client_000001'), 401);
    }

    public function testPendingPollsAnswerAuthorizationPendingThenSlowDown(): void
    {
        $deviceCode = $this->authorize()->deviceCode;

        $this->assertProtocolError('authorization_pending', fn () => $this->poll($deviceCode));
        // An immediate second poll is sooner than the 5 second interval.
        $slowDown = $this->assertProtocolError('slow_down', fn () => $this->poll($deviceCode));
        self::assertStringContainsString('10 seconds', $slowDown->getMessage());
        self::assertSame(10, $this->codes[$deviceCode]->getInterval());
        self::assertSame([], $this->issuedAccessTokens);
    }

    public function testApprovedCodeIssuesOneProofBoundTokenPairForTheApprovingUser(): void
    {
        $deviceCode = $this->authorize()->deviceCode;
        $this->codes[$deviceCode]->approve($this->user);

        $tokens = $this->poll($deviceCode);

        self::assertSame('DPoP', $tokens->getTokenType());
        self::assertSame(self::JKT, $this->issuedAccessTokens[0]->getDpopJkt());
        self::assertSame([self::JKT], $this->jwtBindings);
        self::assertSame($this->user, $this->issuedAccessTokens[0]->getUser());
        self::assertSame('fp-tv', $this->issuedMetadata[0]->getClientFingerprint());
        self::assertNotNull($tokens->getRefreshToken());

        $this->assertProtocolError('invalid_grant', fn () => $this->poll($deviceCode));
        self::assertCount(1, $this->issuedAccessTokens);
    }

    public function testAConcurrentPollThatLosesTheRedemptionIssuesNothing(): void
    {
        $deviceCode = $this->authorize()->deviceCode;
        $this->codes[$deviceCode]->approve($this->user);
        $this->redeemed[$deviceCode] = true;

        $this->assertProtocolError('invalid_grant', fn () => $this->poll($deviceCode));
        self::assertSame([], $this->issuedRefreshTokens);
    }

    public function testDeniedCodeAnswersAccessDenied(): void
    {
        $deviceCode = $this->authorize()->deviceCode;
        $this->codes[$deviceCode]->deny();

        $this->assertProtocolError('access_denied', fn () => $this->poll($deviceCode));
    }

    public function testExpiredCodeAnswersExpiredToken(): void
    {
        $deviceCode = $this->authorize()->deviceCode;
        $state = $this->codes[$deviceCode]->getState();
        $this->codes[$deviceCode] = DeviceCode::reconstitute(new DeviceCodeState(
            id: $state->id,
            deviceCode: $state->deviceCode,
            userCode: $state->userCode,
            user: null,
            client: $state->client,
            scopes: $state->scopes,
            verificationUri: $state->verificationUri,
            verificationUriComplete: $state->verificationUriComplete,
            expiresAt: new \DateTimeImmutable('-1 second'),
            interval: $state->interval,
            lastPolledAt: null,
            createdAt: $state->createdAt,
            updatedAt: $state->updatedAt,
        ));

        $this->assertProtocolError('expired_token', fn () => $this->poll($deviceCode));
    }

    public function testAnotherClientCannotRedeemTheCode(): void
    {
        $deviceCode = $this->authorize()->deviceCode;
        $this->codes[$deviceCode]->approve($this->user);
        $otherTv = $this->register(Client::create('Bedroom TV', [], deviceClient: true));

        $this->assertProtocolError('invalid_grant', fn () => $this->poll($deviceCode, $otherTv->getPublicId()->toString()));
        $this->assertProtocolError('invalid_grant', fn () => $this->poll(str_repeat('u', 40)));
        $this->assertProtocolError('invalid_grant', fn () => $this->poll('short'));
        $this->assertProtocolError('invalid_request', fn () => $this->poll(null));
    }

    private function authorize(?string $clientId = null): DeviceAuthorizationDTO
    {
        $handler = new RequestDeviceAuthorizationHandler(
            $this->clientAuthenticator(),
            $this->deviceCodes(),
            new ScopeAllowlist(['profile', 'email', 'library', 'playlist']),
            self::VERIFY,
            900,
            5,
        );

        return $handler(new RequestDeviceAuthorizationCommand($clientId ?? $this->tv->getPublicId()->toString(), ['library', 'admin']));
    }

    private function poll(?string $deviceCode, ?string $clientId = null): \App\Auth\Application\DTO\TokenResponseDTO
    {
        $handler = new ExchangeDeviceCodeHandler($this->clientAuthenticator(), $this->deviceCodes(), $this->tokenPairIssuer());

        return $handler(new ExchangeDeviceCodeCommand(
            clientId: $clientId ?? $this->tv->getPublicId()->toString(),
            clientSecret: null,
            deviceCode: $deviceCode,
            dpopJkt: self::JKT,
            clientFingerprint: 'fp-tv',
        ));
    }

    private function deviceCodes(): DeviceCodeRepositoryInterface
    {
        $repository = $this->createStub(DeviceCodeRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function (DeviceCode $code): void {
            $this->codes[$code->getDeviceCode()->toString()] = $code;
        });
        $repository->method('findByDeviceCode')->willReturnCallback(fn (TokenId $id): ?DeviceCode => $this->codes[$id->toString()] ?? null);
        $repository->method('findByUserCode')->willReturn(null);
        $repository->method('redeem')->willReturnCallback(function (DeviceCode $code): bool {
            $id = $code->getDeviceCode()->toString();
            if (isset($this->redeemed[$id])) {
                return false;
            }
            $this->redeemed[$id] = true;
            $code->consume();

            return true;
        });

        return $repository;
    }

    private function assertProtocolError(string $error, callable $action, int $status = 400): OAuthProtocolException
    {
        try {
            $action();
        } catch (OAuthProtocolException $exception) {
            self::assertSame($error, $exception->error, $exception->getMessage());
            self::assertSame($status, $exception->statusCode);

            return $exception;
        }

        self::fail(sprintf('Expected the OAuth error "%s".', $error));
    }
}
