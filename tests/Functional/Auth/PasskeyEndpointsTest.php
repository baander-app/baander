<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Tests\Fixtures\Auth\WebAuthnTestCredential;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passkey sign-in options name no account and list no credential. The passkey check endpoint runs
 * the checks passkey sign-in runs and answers every refusal alike. Registration asks for a
 * discoverable credential and answers a refused ceremony without the verifier's text. It stores a
 * credential that comes without an attestation statement and refuses the statement formats its
 * options never request.
 */
final class PasskeyEndpointsTest extends RateLimitTestCase
{
    use EnrolsTotp;

    private const string CLIENT = '198.51.100.170';
    private const string PASSWORD = 'password123';

    public function testSignInOptionsListNoCredentialEvenForANamedAccount(): void
    {
        $user = $this->createTestUser();
        $credential = $this->storePasskey($user);

        $response = $this->requestFrom(self::CLIENT, 'POST', '/api/auth/passkey/authenticate/options', content: ['userId' => $user->getPublicId()->toString()]);

        $data = $this->assertJsonResponse($response, 200, 'data')['data'];
        self::assertSame([], $data['options']['allowCredentials']);
        self::assertStringNotContainsString($credential->passkey()->getCredentialId(), (string) $response->getContent());
    }

    public function testTheCheckEndpointNamesTheOwnerOfAVerifiedAssertion(): void
    {
        $user = $this->createTestUser();
        $credential = $this->storePasskey($user);

        $response = $this->check($this->assertion($credential));

        self::assertSame(['userId' => $user->getPublicId()->toString()], $this->assertJsonResponse($response, 200, 'data')['data']);
    }

    public function testAnUnknownCredentialABadSignatureAndAReusedChallengeGetOneAnswer(): void
    {
        $user = $this->createTestUser();
        $credential = $this->storePasskey($user);
        $unregistered = new WebAuthnTestCredential($user->getPublicId()->toString(), $this->relyingPartyId());
        $badSignature = $this->assertion($credential);
        $badSignature['response']['response']['signature'] = rtrim(strtr(str_repeat('x', 64) |> base64_encode(...), '+/', '-_'), '=');
        $reused = $this->assertion($credential);
        $this->assertJsonResponse($this->check($reused), 200);

        $answers = [
            'unknown credential' => $this->check($this->assertion($unregistered)),
            'bad signature' => $this->check($badSignature),
            'reused challenge' => $this->check($reused),
        ];

        foreach ($answers as $case => $answer) {
            $this->assertRefused($answer, $case);
        }
    }

    public function testACounterThatWentBackwardsIsRefusedAsSignInRefusesIt(): void
    {
        $user = $this->createTestUser();
        $credential = $this->storePasskey($user, storedCounter: 5);

        $this->assertRefused($this->check($this->assertion($credential)));

        $this->entityManager->clear();
        self::assertSame(5, $this->passkeys()->ofCredentialId($credential->passkey()->getCredentialId())?->getCounter());
    }

    public function testAnAccountWithTotpOnRefusesAPasskeyThatDidNotVerifyTheUser(): void
    {
        $user = $this->createTestUser();
        $this->enrolTotp($user);
        $credential = $this->storePasskey($user, verifiesUser: false);

        $this->assertRefused($this->check($this->assertion($credential)));
    }

    public function testADisabledAccountIsRefused(): void
    {
        $user = $this->createTestUser();
        $credential = $this->storePasskey($user);
        $user->disable();
        $this->userRepository->save($user);

        $this->assertRefused($this->check($this->assertion($credential)));
    }

    public function testRegistrationAsksForADiscoverableCredential(): void
    {
        $user = $this->createTestUser(verified: true);

        $selection = $this->registrationOptions($user)['options']['authenticatorSelection'];

        self::assertSame('required', $selection['residentKey']);
        self::assertTrue($selection['requireResidentKey']);
    }

    public function testARefusedRegistrationAnswersAFixedCodeAndLogsTheReason(): void
    {
        $logs = $this->captureLogs();
        $user = $this->createTestUser(verified: true);
        $options = $this->registrationOptions($user);
        $credential = new WebAuthnTestCredential($user->getPublicId()->toString(), $options['options']['rp']['id']);

        $response = $this->authenticatedRequest('POST', '/api/auth/passkey/register', $user, [
            'challengeKey' => $options['challengeKey'],
            'response' => $credential->attest($options['options']['challenge'], 'https://evil.baander.app'),
            'name' => 'Laptop',
        ]);

        $error = $this->assertJsonResponse($response, 400, 'error')['error'];
        self::assertSame(['reason' => 'passkey_registration_failed'], $error['details'] ?? null);
        self::assertSame('Passkey registration verification failed.', $error['message']);
        self::assertStringContainsString('origin', $this->loggedRefusal($logs)->getMessage());
        self::assertSame([], $this->passkeys()->forUser($user->getId()));
    }

    public function testARegistrationWithoutAnAttestationStatementStoresThePasskey(): void
    {
        $user = $this->createTestUser(verified: true);
        $options = $this->registrationOptions($user);
        $relyingPartyId = $options['options']['rp']['id'];
        $credential = new WebAuthnTestCredential($user->getPublicId()->toString(), $relyingPartyId);

        $response = $this->authenticatedRequest('POST', '/api/auth/passkey/register', $user, [
            'challengeKey' => $options['challengeKey'],
            'response' => $credential->attest($options['options']['challenge'], 'https://' . $relyingPartyId),
            'name' => 'Laptop',
        ]);

        $this->assertJsonResponse($response, 201, 'data');
        self::assertCount(1, $this->passkeys()->forUser($user->getId()));
    }

    /**
     * The statement formats registration options never ask an authenticator for.
     *
     * @return iterable<string, array{string}>
     */
    public static function unrequestedAttestationFormats(): iterable
    {
        yield 'FIDO U2F' => ['fido-u2f'];
        yield 'Android Key' => ['android-key'];
        yield 'Apple' => ['apple'];
        yield 'TPM' => ['tpm'];
    }

    #[DataProvider('unrequestedAttestationFormats')]
    public function testARegistrationInAnUnrequestedAttestationFormatIsRefused(string $format): void
    {
        $logs = $this->captureLogs();
        $user = $this->createTestUser(verified: true);
        $options = $this->registrationOptions($user);
        $relyingPartyId = $options['options']['rp']['id'];
        $credential = new WebAuthnTestCredential($user->getPublicId()->toString(), $relyingPartyId);

        $response = $this->authenticatedRequest('POST', '/api/auth/passkey/register', $user, [
            'challengeKey' => $options['challengeKey'],
            'response' => $credential->attest($options['options']['challenge'], 'https://' . $relyingPartyId, $format),
            'name' => 'Security key',
        ]);

        $error = $this->assertJsonResponse($response, 400, 'error')['error'];
        self::assertSame(['reason' => 'passkey_registration_failed'], $error['details'] ?? null);
        self::assertSame('The attestation statement format "' . $format . '" is not supported.', $this->loggedRefusal($logs)->getMessage());
        self::assertSame([], $this->passkeys()->forUser($user->getId()));
    }

    /** The reason behind the one refused registration in the captured log. */
    private function loggedRefusal(TestHandler $logs): \Throwable
    {
        $refusals = $logs->getRecords()
            |> (static fn (array $records): array => array_filter($records, static fn (LogRecord $record): bool => $record->message === 'Passkey registration refused.'))
            |> array_values(...);
        self::assertCount(1, $refusals, 'Logged: ' . implode(' | ', array_map(static fn (LogRecord $record): string => $record->channel . ': ' . $record->message, $logs->getRecords())));
        $reason = $refusals[0]->context['exception'] ?? null;
        self::assertInstanceOf(\Throwable::class, $reason);

        return $reason;
    }

    private function assertRefused(Response $response, string $case = ''): void
    {
        self::assertSame(401, $response->getStatusCode(), $case . ': ' . $response->getContent());
        self::assertSame(
            ['error' => ['message' => 'Passkey authentication failed.', 'code' => 401, 'details' => ['reason' => 'passkey_auth_failed']]],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
            $case,
        );
    }

    /** @param array{challengeKey: string, response: array<string, mixed>} $assertion */
    private function check(array $assertion): Response
    {
        return $this->requestFrom(self::CLIENT, 'POST', '/api/auth/passkey/authenticate', content: $assertion);
    }

    /** @return array{challengeKey: string, response: array<string, mixed>} */
    private function assertion(WebAuthnTestCredential $credential): array
    {
        $options = $this->signInOptions();

        return [
            'challengeKey' => $options['challengeKey'],
            'response' => $credential->assert($options['options']['challenge'], 'https://' . $options['options']['rpId']),
        ];
    }

    /** @return array{challengeKey: string, options: array<string, mixed>} */
    private function signInOptions(): array
    {
        $response = $this->requestFrom(self::CLIENT, 'POST', '/api/auth/passkey/authenticate/options', content: []);

        return $this->assertJsonResponse($response, 200, 'data')['data'];
    }

    /** @return array{challengeKey: string, options: array<string, mixed>} */
    private function registrationOptions(User $user): array
    {
        $response = $this->authenticatedRequest('POST', '/api/auth/passkey/options', $user, ['currentPassword' => self::PASSWORD]);

        return $this->assertJsonResponse($response, 200, 'data')['data'];
    }

    private function relyingPartyId(): string
    {
        return $this->signInOptions()['options']['rpId'];
    }

    private function storePasskey(User $user, int $storedCounter = 0, bool $verifiesUser = true): WebAuthnTestCredential
    {
        $credential = new WebAuthnTestCredential($user->getPublicId()->toString(), $this->relyingPartyId(), verifiesUser: $verifiesUser);
        $passkey = $credential->passkey();
        $passkey->updateCounter($storedCounter);
        $this->passkeys()->save($passkey, $user->getId());

        return $credential;
    }

    private function passkeys(): PasskeyRepositoryInterface
    {
        $passkeys = static::getContainer()->get(PasskeyRepositoryInterface::class);
        self::assertInstanceOf(PasskeyRepositoryInterface::class, $passkeys);

        return $passkeys;
    }

    /** Auth services log to the auth channel. */
    private function captureLogs(): TestHandler
    {
        $logger = static::getContainer()->get('monolog.logger.auth');
        self::assertInstanceOf(Logger::class, $logger);
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        return $handler;
    }
}
