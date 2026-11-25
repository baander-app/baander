<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Application\Command\Totp\DisableTotpCommand;
use App\Auth\Application\Command\User\ChangePasswordCommand;
use App\Auth\Application\Command\User\DisableUserCommand;
use App\Auth\Application\Command\User\EnableUserCommand;
use App\Auth\Application\Command\User\SetUserPasswordCommand;
use App\Auth\Application\Command\User\SetUserRolesCommand;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Application\Port\TotpSecretCipherInterface;
use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\PasswordChangeOAuthKernel;
use App\Tests\Fixtures\Auth\RecordingLiveConnections;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\Auth\WebAuthnTestCredential;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every way of changing a password ends the sessions the old password started, and so does
 * every change of the account's other sign-in factors: turning TOTP on or off, and adding or
 * removing a passkey. Through the production firewall with real PostgreSQL and Redis. Only a
 * user's own change keeps the session that made it, and only while no other change has ended
 * that session first.
 */
final class PasswordChangeSessionRevocationTest extends TestCase
{
    private const string ORIGIN = 'https://baander.app';
    private const string PASSWORD = 'session-test-password';
    private const string TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    private static string $sharedDirectory;
    private PasswordChangeOAuthKernel $kernel;
    private Connection $connection;
    private UserRepositoryInterface $users;
    /** @var list<User> */
    private array $created = [];
    /** @var list<string> ids of the third-party clients a test registered */
    private array $createdClients = [];
    private User $user;
    private string $ip;
    private Connection $observer;

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$sharedDirectory)) {
            new Filesystem()->remove(self::$sharedDirectory);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Disposable PostgreSQL and Redis services are required.');
        }
        if (!isset(self::$sharedDirectory)) {
            self::$sharedDirectory = sys_get_temp_dir() . '/baander-password-sessions-' . (random_bytes(8) |> bin2hex(...));
            self::assertTrue(mkdir(self::$sharedDirectory, 0700));
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            self::assertNotFalse($key);
            self::assertTrue(openssl_pkey_export($key, $privateKey));
            $details = openssl_pkey_get_details($key);
            self::assertIsArray($details);
            self::assertSame(strlen($privateKey), file_put_contents(self::$sharedDirectory . '/private.pem', $privateKey));
            self::assertSame(strlen($details['key']), file_put_contents(self::$sharedDirectory . '/public.pem', $details['key']));
            self::assertTrue(chmod(self::$sharedDirectory . '/private.pem', 0600));
            self::assertTrue(chmod(self::$sharedDirectory . '/public.pem', 0600));
        }
        $this->kernel = new PasswordChangeOAuthKernel(self::$sharedDirectory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->connection = $manager->getConnection();
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->users = $users;
        // Login and reset limits are per client IP; isolate each test's budget.
        $this->ip = sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
        $this->user = $this->createUser(['ROLE_USER']);

        $spaClientId = $container->getParameter('auth.spa_client_id');
        self::assertIsString($spaClientId);
        if ($this->connection->fetchOne('SELECT 1 FROM oauth_clients WHERE public_id = ?', [$spaClientId]) === false) {
            $manager->persist(new ClientEntity(
                PublicId::fromString($spaClientId),
                'Password session SPA',
                json_encode([self::ORIGIN . '/callback'], JSON_THROW_ON_ERROR),
                passwordClient: true,
                firstParty: true,
            ));
            $manager->flush();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $user) {
            $userId = $user->getId()->toString();
            // These production-kernel writes commit independently of DAMA. Durable events
            // have no user foreign key and must leave with the account that produced them.
            $this->connection->executeStatement(
                "DELETE FROM domain_event_outbox WHERE payload->>'user_id' = :userId",
                ['userId' => $userId],
            );
            // Refresh tokens and token metadata cascade from access tokens.
            $this->connection->executeStatement('DELETE FROM oauth_access_tokens WHERE user_id = ?', [$userId]);
            $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [$userId]);
        }
        foreach ($this->createdClients as $clientId) {
            $this->connection->executeStatement('DELETE FROM oauth_clients WHERE id = ?', [$clientId]);
        }
        if (isset($this->observer)) {
            $this->observer->close();
        }
        $this->kernel->shutdown();
        parent::tearDown();
    }

    public function testOwnChangeKeepsTheRequestingSessionAndEndsTheOthers(): void
    {
        [$current, $currentKey] = $this->signIn($this->user);
        [$other, $otherKey] = $this->signIn($this->user);
        $pairedDevice = $this->issueDelegatedToken();
        $this->issueResetToken();

        $response = $this->changeOwnPassword($current['accessToken'], $currentKey, 'changed-by-owner-1');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(200, $this->me($current['accessToken'], $currentKey)->getStatusCode());
        $refreshed = $this->refresh($current['refreshToken'], $currentKey);
        self::assertSame(200, $refreshed->getStatusCode(), 'The current session can still refresh.');
        $rotated = json_decode((string) $refreshed->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame(200, $this->me($rotated['accessToken'], $currentKey)->getStatusCode(), 'The rotated access token authenticates.');
        self::assertSame(200, $this->refresh($rotated['refreshToken'], $currentKey)->getStatusCode(), 'The rotated chain refreshes again.');
        $this->assertSignedOut($other, $otherKey);
        self::assertTrue($this->accessTokenRevoked($pairedDevice), "The paired device's token is revoked too.");
        $this->assertNoResetToken();
    }

    /**
     * The change closes the WebSocket connections of every session it ended as soon as it
     * commits, without waiting for the reconciliation: every access token of each ended
     * chain, including one a rotation replaced while its socket stayed open, and the paired
     * device's token. The kept session's connections stay open.
     */
    public function testTheChangeClosesTheConnectionsOfTheSessionsItEndsWhenItCommits(): void
    {
        [$current, $currentKey] = $this->signIn($this->user);
        [$other, $otherKey] = $this->signIn($this->user);
        $rotated = $this->refresh($other['refreshToken'], $otherKey);
        self::assertSame(200, $rotated->getStatusCode(), (string) $rotated->getContent());
        $otherRotated = json_decode((string) $rotated->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $pairedDevice = $this->issueDelegatedToken();
        $offset = RecordingLiveConnections::size(self::$sharedDirectory);

        $response = $this->changeOwnPassword($current['accessToken'], $currentKey, 'changed-by-owner-7');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $closes = RecordingLiveConnections::closedAccessTokens(self::$sharedDirectory, (int) getmypid(), $offset);
        self::assertCount(1, $closes, 'One close request carries every ended session.');
        self::assertEqualsCanonicalizing(
            [self::tokenId($other['accessToken']), self::tokenId($otherRotated['accessToken']), $pairedDevice],
            $closes[0],
        );
        self::assertSame(200, $this->me($current['accessToken'], $currentKey)->getStatusCode());
    }

    /**
     * A close the web server could not carry out does not fail the change: the sessions stay
     * ended, and one error names the user and how many connections were meant to close,
     * never a token.
     */
    public function testAChangeWhoseSocketCloseFailedStillCommitsAndLogsTheUser(): void
    {
        [$current, $currentKey] = $this->signIn($this->user);
        [$other, $otherKey] = $this->signIn($this->user);
        $fault = RecordingLiveConnections::closeFaultPath(self::$sharedDirectory);
        self::assertNotFalse(file_put_contents($fault, '1'));

        try {
            $response = $this->changeOwnPassword($current['accessToken'], $currentKey, 'changed-by-owner-8');
        } finally {
            self::assertTrue(unlink($fault));
        }

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSignedOut($other, $otherKey);
        $this->signIn($this->user, 'changed-by-owner-8');
        $errors = array_filter(
            $this->prodLogRecords(),
            fn (array $record): bool => ($record['context']['user'] ?? null) === $this->user->getId()->toString()
                && str_contains((string) $record['message'], 'did not close'),
        ) |> array_values(...);
        self::assertCount(1, $errors, 'One error records the close the web server did not finish.');
        self::assertSame('ERROR', $errors[0]['level_name']);
        self::assertSame(1, $errors[0]['context']['connections'] ?? null);
        self::assertStringNotContainsString(self::tokenId($other['accessToken']), json_encode($errors[0], JSON_THROW_ON_ERROR));
    }

    /**
     * Session B asks for a change while a change from session A holds the account's row. B
     * waits, A's change commits and ends B, and B's change is then refused: it changes
     * nothing, so A stays signed in and A's password stands.
     */
    public function testAChangeFromASessionThatAConcurrentChangeEndedIsRefusedAndChangesNothing(): void
    {
        $this->assertReadCommitted();
        [$current, $currentKey] = $this->signIn($this->user);
        [$other, $otherKey, $otherKeyPath] = $this->signInWithExportedKey();
        $epochBefore = $this->credentialEpoch();

        $this->connection->beginTransaction();
        try {
            $this->changePasswordInThisTransaction($current['accessToken'], 'changed-by-owner-9');
            $late = $this->startSessionRequest($otherKeyPath, $other['accessToken'], 'PUT', '/api/auth/me/password', [
                'currentPassword' => self::PASSWORD,
                'newPassword' => 'changed-by-ended-session',
            ]);
            $this->awaitWaitOnThisSession($late);
            $this->connection->commit();
        } catch (\Throwable $failure) {
            $this->connection->rollBack();
            throw $failure;
        }

        $answer = $this->finish($late);

        self::assertSame(401, $answer['status'], json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertSame('credentials_changed', $answer['body']['error']['details']['reason'] ?? null);
        self::assertSame($epochBefore + 1, $this->credentialEpoch(), 'Only the first change advanced the epoch.');
        self::assertTrue(password_verify('changed-by-owner-9', $this->storedPasswordHash()), "The first change's password stands.");
        $kept = $this->me($current['accessToken'], $currentKey);
        self::assertSame(200, $kept->getStatusCode(), 'The session that changed it stays signed in.');
        self::assertSame(200, $this->refresh($current['refreshToken'], $currentKey)->getStatusCode());
        $this->assertSignedOut($other, $otherKey);
    }

    /**
     * Two tabs of one session each change the password: the second waits for the first, finds
     * its own session moved to the new credential epoch, and goes through. The session stays
     * signed in and the second password stands.
     */
    public function testTwoChangesFromOneSessionBothSucceed(): void
    {
        $this->assertReadCommitted();
        [$session, $key, $keyPath] = $this->signInWithExportedKey();

        $this->connection->beginTransaction();
        try {
            $this->changePasswordInThisTransaction($session['accessToken'], 'changed-in-first-tab');
            $secondTab = $this->startSessionRequest($keyPath, $session['accessToken'], 'PUT', '/api/auth/me/password', [
                'currentPassword' => self::PASSWORD,
                'newPassword' => 'changed-in-second-tab',
            ]);
            $this->awaitWaitOnThisSession($secondTab);
            $this->connection->commit();
        } catch (\Throwable $failure) {
            $this->connection->rollBack();
            throw $failure;
        }

        $answer = $this->finish($secondTab);

        self::assertSame(200, $answer['status'], json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertTrue(password_verify('changed-in-second-tab', $this->storedPasswordHash()));
        self::assertSame(200, $this->me($session['accessToken'], $key)->getStatusCode());
        self::assertSame(200, $this->refresh($session['refreshToken'], $key)->getStatusCode());
    }

    /**
     * Turning TOTP off from session A keeps A signed in. Session B and a paired TV are signed
     * out, and their WebSocket connections close as soon as the change commits.
     */
    public function testTurningTotpOffKeepsTheRequestingSessionAndEndsTheOthers(): void
    {
        [$current, $currentKey] = $this->signIn($this->user);
        [$other, $otherKey] = $this->signIn($this->user);
        $pairedDevice = $this->issueDelegatedToken();
        $this->enrolTotp();
        $offset = RecordingLiveConnections::size(self::$sharedDirectory);

        $response = $this->send('POST', '/api/auth/totp/disable', $current['accessToken'], $currentKey, [
            'currentPassword' => self::PASSWORD,
            'totpCode' => self::currentTotpCode(),
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStillSignedIn($current, $currentKey);
        $this->assertSignedOut($other, $otherKey);
        self::assertTrue($this->accessTokenRevoked($pairedDevice), "The paired device's token is revoked too.");
        $closes = RecordingLiveConnections::closedAccessTokens(self::$sharedDirectory, (int) getmypid(), $offset);
        self::assertCount(1, $closes, 'One close request carries every ended session.');
        self::assertEqualsCanonicalizing([self::tokenId($other['accessToken']), $pairedDevice], $closes[0]);
    }

    /**
     * TOTP setup and enable are two requests. The session that sends the code turning TOTP on
     * stays signed in, even when another session started the setup; every other session ends.
     */
    public function testTurningTotpOnKeepsTheSessionThatSentTheCode(): void
    {
        [$starter, $starterKey] = $this->signIn($this->user);
        [$confirmer, $confirmerKey] = $this->signIn($this->user);
        $setup = $this->send('POST', '/api/auth/totp/setup', $starter['accessToken'], $starterKey, ['currentPassword' => self::PASSWORD]);
        self::assertSame(200, $setup->getStatusCode(), (string) $setup->getContent());
        $secret = json_decode((string) $setup->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['secret'];

        $response = $this->send('POST', '/api/auth/totp/enable', $confirmer['accessToken'], $confirmerKey, ['code' => TOTP::create($secret)->now()]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStillSignedIn($confirmer, $confirmerKey);
        $this->assertSignedOut($starter, $starterKey);
    }

    /**
     * Registration options and registration are two requests. The session that completes the
     * registration stays signed in, even when another session asked for the options; every
     * other session ends.
     */
    public function testRegisteringAPasskeyKeepsTheSessionThatCompletedIt(): void
    {
        [$starter, $starterKey] = $this->signIn($this->user);
        [$completer, $completerKey] = $this->signIn($this->user);
        $registration = $this->passkeyRegistration($starter, $starterKey, 'Laptop');

        $response = $this->send('POST', '/api/auth/passkey/register', $completer['accessToken'], $completerKey, $registration);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(1, $this->storedPasskeys());
        $this->assertStillSignedIn($completer, $completerKey);
        $this->assertSignedOut($starter, $starterKey);
    }

    public function testRemovingAPasskeyKeepsTheRequestingSessionAndEndsTheOthers(): void
    {
        [$current, $currentKey] = $this->signIn($this->user);
        [$other, $otherKey] = $this->signIn($this->user);
        $passkey = Passkey::create(Uuid::v7(), 'Laptop', 'credential-' . (random_bytes(8) |> bin2hex(...)), [], 0);
        $this->passkeys()->save($passkey, $this->user->getId());

        $response = $this->send('DELETE', '/api/auth/passkey/' . $passkey->getPublicId()->toString(), $current['accessToken'], $currentKey, [
            'currentPassword' => self::PASSWORD,
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(0, $this->storedPasskeys());
        $this->assertStillSignedIn($current, $currentKey);
        $this->assertSignedOut($other, $otherKey);
    }

    /** @return iterable<string, array{string}> */
    public static function competingChanges(): iterable
    {
        yield 'a password change' => ['password'];
        yield 'turning TOTP off' => ['totp'];
    }

    /**
     * Session B asks for registration options, then session A changes a credential. B's
     * registration completes while A's change holds the account's row, and is refused once A's
     * change has ended B: no passkey is stored and A stays signed in.
     */
    #[DataProvider('competingChanges')]
    public function testAPasskeyRegistrationWhoseSessionAConcurrentChangeEndedStoresNothing(string $competingChange): void
    {
        $this->assertReadCommitted();
        [$current, $currentKey] = $this->signIn($this->user);
        [$other, $otherKey, $otherKeyPath] = $this->signInWithExportedKey();
        $registration = $this->passkeyRegistration($other, $otherKey, 'Started before the change');
        if ($competingChange === 'totp') {
            $this->enrolTotp();
        }

        $this->connection->beginTransaction();
        try {
            match ($competingChange) {
                'password' => $this->changePasswordInThisTransaction($current['accessToken'], 'changed-during-registration'),
                'totp' => $this->turnTotpOffInThisTransaction($current['accessToken']),
            };
            $late = $this->startSessionRequest($otherKeyPath, $other['accessToken'], 'POST', '/api/auth/passkey/register', $registration);
            $this->awaitWaitOnThisSession($late);
            $this->connection->commit();
        } catch (\Throwable $failure) {
            $this->connection->rollBack();
            throw $failure;
        }

        $answer = $this->finish($late);

        self::assertSame(401, $answer['status'], json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertSame('credentials_changed', $answer['body']['error']['details']['reason'] ?? null);
        self::assertSame(0, $this->storedPasskeys(), 'The ended session stored no passkey.');
        self::assertSame(200, $this->me($current['accessToken'], $currentKey)->getStatusCode(), 'The session that made the change stays signed in.');
        $this->assertSignedOut($other, $otherKey);
    }

    public function testRedeemingAResetTokenEndsEverySession(): void
    {
        [$first, $firstKey] = $this->signIn($this->user);
        [$second, $secondKey] = $this->signIn($this->user);
        $this->issueResetToken('session-reset-token');

        $response = $this->send('POST', '/api/auth/password/reset', json: ['token' => 'session-reset-token', 'password' => 'changed-by-reset-2']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertSignedOut($first, $firstKey);
        $this->assertSignedOut($second, $secondKey);
        $this->signIn($this->user, 'changed-by-reset-2');
    }

    /** @return iterable<string, array{string}> */
    public static function operatorChangesDuringAReset(): iterable
    {
        yield 'disabling the account' => ['disable'];
        yield 'demoting the admin' => ['demote'];
    }

    /**
     * An operator disables or demotes the account and commits while a reset redemption has
     * redeemed its token and waits on the account's row. The operator's change stands: a reset
     * of an account disabled meanwhile is refused as any disabled account's is, and a demoted
     * admin's reset sets the password and leaves the roles as the operator set them.
     */
    #[DataProvider('operatorChangesDuringAReset')]
    public function testAResetRacingAnOperatorChangeKeepsTheOperatorsChange(string $operatorChange): void
    {
        $this->assertReadCommitted();
        $this->bus()->dispatch(new SetUserRolesCommand($this->user->getEmail(), ['ROLE_USER', 'ROLE_ADMIN']));
        $this->issueResetToken('racing-reset-token');

        $this->connection->beginTransaction();
        try {
            $this->bus()->dispatch(match ($operatorChange) {
                'disable' => new DisableUserCommand($this->user->getEmail()),
                'demote' => new SetUserRolesCommand($this->user->getEmail(), ['ROLE_USER']),
            });
            $reset = $this->startSignedOutRequest('POST', '/api/auth/password/reset', ['token' => 'racing-reset-token', 'password' => 'changed-by-racing-reset']);
            $this->awaitWaitOnThisSession($reset);
            $this->connection->commit();
        } catch (\Throwable $failure) {
            $this->connection->rollBack();
            throw $failure;
        }

        $answer = $this->finish($reset);

        $stored = $this->connection->fetchAssociative('SELECT disabled, roles, password FROM users WHERE id = ?', [$this->user->getId()->toString()]);
        self::assertIsArray($stored);
        if ($operatorChange === 'disable') {
            self::assertTrue($stored['disabled'], 'The account stays disabled.');
            self::assertSame(400, $answer['status'], json_encode($answer, JSON_THROW_ON_ERROR));
            self::assertTrue(password_verify(self::PASSWORD, (string) $stored['password']), 'The refused reset left the password.');
        } else {
            self::assertSame(['ROLE_USER'], json_decode((string) $stored['roles'], true, flags: JSON_THROW_ON_ERROR), 'The account stays demoted.');
            self::assertSame(200, $answer['status'], json_encode($answer, JSON_THROW_ON_ERROR));
            self::assertTrue(password_verify('changed-by-racing-reset', (string) $stored['password']));
        }
    }

    /**
     * Confirming a pending email change ends the user's other sessions as a password change
     * does: the session that opened the link stays signed in, every other session and the
     * paired device's token end, and their connections close when the change commits.
     */
    public function testConfirmingAnEmailChangeKeepsTheSessionThatOpenedTheLinkAndEndsTheOthers(): void
    {
        [$current, $currentKey] = $this->signIn($this->user);
        [$other, $otherKey] = $this->signIn($this->user);
        $pairedDevice = $this->issueDelegatedToken();
        $address = 'password-session-new-' . (random_bytes(8) |> bin2hex(...)) . '@baander.app';
        $this->requestEmailChange($address, 'session-email-change-token');
        $offset = RecordingLiveConnections::size(self::$sharedDirectory);

        $response = $this->send('POST', '/api/auth/email/verify', $current['accessToken'], $currentKey, ['token' => 'session-email-change-token']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($address, $this->connection->fetchOne('SELECT email FROM users WHERE id = ?', [$this->user->getId()->toString()]));
        self::assertSame(200, $this->me($current['accessToken'], $currentKey)->getStatusCode());
        self::assertSame(200, $this->refresh($current['refreshToken'], $currentKey)->getStatusCode(), 'The session that opened the link can still refresh.');
        $this->assertSignedOut($other, $otherKey);
        self::assertTrue($this->accessTokenRevoked($pairedDevice), "The paired device's token is revoked too.");
        $closes = RecordingLiveConnections::closedAccessTokens(self::$sharedDirectory, (int) getmypid(), $offset);
        self::assertCount(1, $closes, 'One close request carries every ended session.');
        self::assertEqualsCanonicalizing([self::tokenId($other['accessToken']), $pairedDevice], $closes[0]);
    }

    public function testAnAdministratorResetEndsEverySession(): void
    {
        [$session, $key] = $this->signIn($this->user);
        $this->issueResetToken();
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        [$adminSession, $adminKey] = $this->signIn($admin);

        $response = $this->send(
            'POST',
            '/api/admin/users/' . $this->user->getPublicId()->toString() . '/reset-password',
            $adminSession['accessToken'],
            $adminKey,
            ['password' => 'changed-by-admin-3'],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertSignedOut($session, $key);
        $this->assertNoResetToken();
        self::assertSame(200, $this->me($adminSession['accessToken'], $adminKey)->getStatusCode(), 'Only the target user is signed out.');
    }

    public function testTheCliResetEndsEverySession(): void
    {
        [$session, $key] = $this->signIn($this->user);
        $this->issueResetToken();

        $command = new Application($this->kernel)->find('app:user:reset-password');
        $tester = new CommandTester($command);
        $tester->setInputs(['changed-by-cli-4']);
        $tester->execute(['identifier' => $this->user->getEmail()]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $this->assertSignedOut($session, $key);
        $this->assertNoResetToken();
        $this->signIn($this->user, 'changed-by-cli-4');
    }

    /**
     * A session issued before the account's credentials changed stops working even when
     * nothing revoked its tokens: the access token and the refresh chain carry the credential
     * epoch they were issued under, and the account's epoch has moved past it.
     */
    public function testASessionIssuedUnderAnEarlierCredentialEpochIsRefused(): void
    {
        [$session, $key] = $this->signIn($this->user);
        self::assertSame(200, $this->me($session['accessToken'], $key)->getStatusCode());

        $this->connection->executeStatement(
            'UPDATE users SET credential_epoch = credential_epoch + 1 WHERE id = ?',
            [$this->user->getId()->toString()],
        );

        $this->assertSignedOut($session, $key);
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM oauth_access_tokens WHERE user_id = ? AND revoked',
            [$this->user->getId()->toString()],
        ), 'Nothing was revoked; the epoch alone refuses the session.');
    }

    /**
     * A login checks the password before a concurrent change commits and reaches issuance
     * while the change still holds the account's row. The login waits for the change and
     * then refuses: no session issued under the old password survives the change.
     */
    public function testALoginWhosePasswordCheckPassedBeforeAChangeCommitsGetsNoSession(): void
    {
        $this->assertReadCommitted();
        $this->connection->beginTransaction();
        try {
            $this->setPassword('changed-during-login-5');
            $login = $this->startLogin(self::PASSWORD);
            $this->awaitWaitOnThisSession($login);
            $this->connection->commit();
        } catch (\Throwable $failure) {
            $this->connection->rollBack();
            throw $failure;
        }

        $answer = $this->finish($login);

        self::assertSame(401, $answer['status'], json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->liveAccessTokens(), 'The login issued no session.');
        $storedHash = $this->connection->fetchOne('SELECT password FROM users WHERE id = ?', [$this->user->getId()->toString()]);
        self::assertIsString($storedHash);
        self::assertTrue(password_verify('changed-during-login-5', $storedHash), 'The pending hash upgrade must preserve the concurrently changed password.');
        $this->signIn($this->user, 'changed-during-login-5');
    }

    /**
     * A login checks the password before an operator's disable commits and reaches issuance
     * while the disable still holds the account's row. The disable advances the credential
     * epoch, so the login refuses: it leaves no session that would work once the account is
     * enabled again.
     */
    public function testALoginWhosePasswordCheckPassedBeforeADisableCommitsGetsNoSession(): void
    {
        $this->assertReadCommitted();
        $this->connection->beginTransaction();
        try {
            $this->bus()->dispatch(new DisableUserCommand($this->user->getEmail()));
            $login = $this->startLogin(self::PASSWORD);
            $this->awaitWaitOnThisSession($login);
            $this->connection->commit();
        } catch (\Throwable $failure) {
            $this->connection->rollBack();
            throw $failure;
        }

        $answer = $this->finish($login);
        $this->bus()->dispatch(new EnableUserCommand($this->user->getEmail()));

        self::assertSame(401, $answer['status'], json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->liveAccessTokens(), 'The login issued no session that the enable could bring back.');
        $this->signIn($this->user);
    }

    /**
     * A refresh that reaches its transaction while a password change holds the account's row
     * waits for the change, then refuses: the chain the change ended issues nothing.
     */
    public function testARefreshRacingAPasswordChangeLeavesNoUsableToken(): void
    {
        $this->assertReadCommitted();
        [$session, $key, $keyPath] = $this->signInWithExportedKey();

        $this->connection->beginTransaction();
        try {
            $this->setPassword('changed-during-refresh-6');
            $refresh = $this->startRefresh($keyPath, $session['refreshToken']);
            $this->awaitWaitOnThisSession($refresh);
            $this->connection->commit();
        } catch (\Throwable $failure) {
            $this->connection->rollBack();
            throw $failure;
        }

        $answer = $this->finish($refresh);

        self::assertSame(401, $answer['status'], json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->liveAccessTokens(), 'No access token of the user survives.');
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM oauth_refresh_tokens r JOIN oauth_access_tokens a ON a.id = r.access_token_id WHERE a.user_id = ? AND NOT r.revoked',
            [$this->user->getId()->toString()],
        ), 'No refresh token of the user survives.');
        $this->assertSignedOut($session, $key);
    }

    /**
     * A refresh that reaches its transaction while the account's credential epoch moves, with
     * no token revoked, waits for the change and refuses: the epoch comparison under the row
     * lock alone ends the chain's use.
     */
    public function testARefreshRacingAnEpochChangeThatRevokesNothingIsRefused(): void
    {
        $this->assertReadCommitted();
        [$session, , $keyPath] = $this->signInWithExportedKey();

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                'UPDATE users SET credential_epoch = credential_epoch + 1 WHERE id = ?',
                [$this->user->getId()->toString()],
            );
            $refresh = $this->startRefresh($keyPath, $session['refreshToken']);
            $this->awaitWaitOnThisSession($refresh);
            $this->connection->commit();
        } catch (\Throwable $failure) {
            $this->connection->rollBack();
            throw $failure;
        }

        $answer = $this->finish($refresh);

        self::assertSame(401, $answer['status'], json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM oauth_access_tokens WHERE user_id = ?',
            [$this->user->getId()->toString()],
        ), 'The refused refresh issued no access token.');
    }

    /**
     * A login whose issuance waits on the account's row past the issuance lock timeout is
     * answered with 503 and Retry-After, so the client retries, and issues no session.
     */
    public function testALoginWhoseIssuanceTimesOutOnTheAccountsRowAnswers503WithRetryAfter(): void
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->executeQuery('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [$this->user->getId()->toString()])->free();
            $login = $this->startLogin(self::PASSWORD);
            $this->awaitWaitOnThisSession($login);
            $answer = $this->finish($login);
        } finally {
            $this->connection->rollBack();
        }

        self::assertSame(503, $answer['status'], json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertSame('5', $answer['retryAfter'] ?? null);
        self::assertSame(0, $this->liveAccessTokens(), 'The login issued no session.');
    }

    private function assertReadCommitted(): void
    {
        // The fence re-reads the account's epoch in a statement of its own after the lock.
        self::assertSame('read committed', $this->connection->fetchOne('SHOW default_transaction_isolation'));
    }

    private function bus(): MessageBusInterface
    {
        $bus = $this->kernel->getContainer()->get('oauth.acceptance.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    /** An operator password reset through the command bus, inside the transaction this test holds open. */
    private function setPassword(string $password): void
    {
        $this->bus()->dispatch(new SetUserPasswordCommand($this->user->getEmail(), $password));
    }

    /**
     * The user's own password change from the session of this access token, through the
     * command bus inside the transaction this test holds open, as the endpoint dispatches it.
     */
    private function changePasswordInThisTransaction(string $accessToken, string $password): void
    {
        $this->bus()->dispatch(new ChangePasswordCommand(
            userId: $this->user->getId()->toString(),
            currentPassword: self::PASSWORD,
            newPassword: $password,
            currentAccessTokenId: self::tokenId($accessToken),
        ));
    }

    /** The user's own TOTP disable from the session of this access token, inside the transaction this test holds open. */
    private function turnTotpOffInThisTransaction(string $accessToken): void
    {
        $this->bus()->dispatch(new DisableTotpCommand(
            $this->user->getId()->toString(),
            self::PASSWORD,
            self::currentTotpCode(),
            self::tokenId($accessToken),
        ));
    }

    /** Turns TOTP on for the user directly, so no session ends and no time step is spent. */
    private function enrolTotp(): void
    {
        $cipher = $this->kernel->getContainer()->get('oauth.acceptance.totp_cipher');
        self::assertInstanceOf(TotpSecretCipherInterface::class, $cipher);
        $this->user->enableTotp($cipher->seal(self::TOTP_SECRET));
        $this->users->save($this->user);
    }

    private static function currentTotpCode(): string
    {
        return TOTP::create(self::TOTP_SECRET)->now();
    }

    /**
     * Asks for registration options from this session, with the password, and returns the
     * request that completes the registration with a credential the authenticator created for
     * them, as the web client sends it.
     *
     * @param array{accessToken: string, refreshToken: string} $session
     *
     * @return array{challengeKey: string, response: array<string, mixed>, name: string}
     */
    private function passkeyRegistration(array $session, SignedDpopProof $key, string $name): array
    {
        $options = $this->send('POST', '/api/auth/passkey/options', $session['accessToken'], $key, ['currentPassword' => self::PASSWORD]);
        self::assertSame(200, $options->getStatusCode(), (string) $options->getContent());
        $data = json_decode((string) $options->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $credential = new WebAuthnTestCredential($this->user->getPublicId()->toString(), PasswordChangeOAuthKernel::RELYING_PARTY);
        $attestation = $credential->attest($data['options']['challenge'], self::ORIGIN);
        $standard = static fn (string $base64Url): string => base64_encode((string) base64_decode(strtr($base64Url, '-_', '+/'), true));

        return [
            'challengeKey' => $data['challengeKey'],
            'response' => [
                ...$attestation,
                'rawId' => $standard($attestation['rawId']),
                'response' => array_map($standard, $attestation['response']),
            ],
            'name' => $name,
        ];
    }

    private function passkeys(): PasskeyRepositoryInterface
    {
        $passkeys = $this->kernel->getContainer()->get('oauth.acceptance.passkeys');
        self::assertInstanceOf(PasskeyRepositoryInterface::class, $passkeys);

        return $passkeys;
    }

    private function storedPasskeys(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM passkeys WHERE user_id = ?', [$this->user->getId()->toString()]);
    }

    /** @return array{process: resource, output: resource, application: string} */
    private function startLogin(string $password): array
    {
        return $this->startChild('totp-login-race.php', [$this->ip, $this->user->getEmail(), $password, '']);
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array{process: resource, output: resource, application: string}
     */
    private function startSessionRequest(string $keyPath, string $accessToken, string $method, string $path, array $json): array
    {
        return $this->startChild('session-request-race.php', [
            $this->ip,
            $keyPath,
            $accessToken,
            $method,
            $path,
            json_encode($json, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array{process: resource, output: resource, application: string}
     */
    private function startSignedOutRequest(string $method, string $path, array $json): array
    {
        return $this->startChild('session-request-race.php', [$this->ip, '', '', $method, $path, json_encode($json, JSON_THROW_ON_ERROR)]);
    }

    /** @return array{process: resource, output: resource, application: string} */
    private function startRefresh(string $keyPath, string $refreshToken): array
    {
        return $this->startChild('refresh-race.php', [$this->ip, $keyPath, 'refresh', $refreshToken]);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{process: resource, output: resource, application: string}
     */
    private function startChild(string $script, array $arguments): array
    {
        $application = 'password_race_' . (random_bytes(6) |> bin2hex(...));
        $output = tmpfile();
        self::assertIsResource($output);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Auth/' . $script, self::$sharedDirectory, $application, ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        self::assertIsResource($process);

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /**
     * Waits until the child's session waits on a lock (pg_locks shows it ungranted) that this
     * test's open transaction holds. Checked while the child waits, never after a timeout.
     *
     * @param array{process: resource, output: resource, application: string} $child
     */
    private function awaitWaitOnThisSession(array $child): void
    {
        $holder = (int) $this->connection->fetchOne('SELECT pg_backend_pid()');
        $deadline = microtime(true) + 20;
        while (true) {
            $this->observer()->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            $waiting = $this->observer()->fetchOne(
                'SELECT 1 FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid
                  WHERE a.application_name = ? AND NOT l.granted AND ? = ANY (pg_blocking_pids(a.pid))',
                [$child['application'], $holder],
            );
            if ($waiting !== false) {
                return;
            }
            if (microtime(true) > $deadline || !proc_get_status($child['process'])['running']) {
                rewind($child['output']);
                self::fail('The child must wait on the lock the password change holds: ' . stream_get_contents($child['output']));
            }
            usleep(5000);
        }
    }

    /**
     * @param array{process: resource, output: resource, application: string} $child
     *
     * @return array{status: int, retryAfter?: string|null, body: mixed}
     */
    private function finish(array $child): array
    {
        $deadline = microtime(true) + 30;
        while (proc_get_status($child['process'])['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($child['process'], 9);
                self::fail('The child did not finish after the password change committed.');
            }
            usleep(5000);
        }
        rewind($child['output']);
        $result = (string) stream_get_contents($child['output']);
        fclose($child['output']);
        self::assertSame(0, proc_close($child['process']), $result);
        $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsInt($decoded['status'] ?? null, $result);

        /** @var array{status: int, retryAfter?: string|null, body: mixed} $decoded */
        return $decoded;
    }

    private function observer(): Connection
    {
        if (!isset($this->observer)) {
            $url = getenv('OUTBOX_TEST_DATABASE_URL');
            self::assertIsString($url);
            $this->observer = DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url));
        }

        return $this->observer;
    }

    private function liveAccessTokens(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM oauth_access_tokens WHERE user_id = ? AND NOT revoked',
            [$this->user->getId()->toString()],
        );
    }

    private function accessTokenRevoked(string $tokenId): bool
    {
        $revoked = $this->connection->fetchOne('SELECT revoked FROM oauth_access_tokens WHERE token_id = ?', [$tokenId]);
        self::assertNotFalse($revoked, 'The access token must be stored.');

        return (bool) $revoked;
    }

    private function credentialEpoch(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT credential_epoch FROM users WHERE id = ?',
            [$this->user->getId()->toString()],
        );
    }

    private function storedPasswordHash(): string
    {
        $hash = $this->connection->fetchOne('SELECT password FROM users WHERE id = ?', [$this->user->getId()->toString()]);
        self::assertIsString($hash);

        return $hash;
    }

    /**
     * An access token a paired TV holds for the user: issued to a device client, so it is a
     * delegated token, under the user's current credential epoch and outside any refresh chain.
     *
     * @return string the token's id
     */
    private function issueDelegatedToken(): string
    {
        $manager = $this->kernel->getContainer()->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $client = new ClientEntity(new PublicId(), 'Paired TV', '[]', deviceClient: true);
        $manager->persist($client);
        $this->createdClients[] = $client->getId()->toString();
        $user = $manager->find(UserEntity::class, $this->user->getId());
        self::assertInstanceOf(UserEntity::class, $user);
        $tokenId = random_bytes(20) |> bin2hex(...);
        $manager->persist(new AccessTokenEntity(
            $tokenId,
            $client,
            SignInGrant::DeviceCode,
            $user,
            scopes: ['library'],
            expiresAt: new \DateTimeImmutable('+1 hour'),
            credentialEpoch: $this->credentialEpoch(),
        ));
        $manager->flush();

        return $tokenId;
    }

    /** The token id (the jti claim) of an access token. */
    private static function tokenId(string $accessToken): string
    {
        $parts = explode('.', $accessToken);
        self::assertCount(3, $parts);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertIsString($claims['jti'] ?? null);

        return $claims['jti'];
    }

    /**
     * Every record of the kernel's JSON log, in order.
     *
     * @return list<array<string, mixed>>
     */
    private function prodLogRecords(): array
    {
        $path = self::$sharedDirectory . '/logs/prod.log';
        self::assertFileExists($path);
        $records = [];
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if ($line === '') {
                continue;
            }
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($record);
            $records[] = $record;
        }

        return $records;
    }

    /** @return array{array{accessToken: string, refreshToken: string}, SignedDpopProof, string} */
    private function signInWithExportedKey(): array
    {
        $pem = '';
        $generated = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($generated);
        self::assertTrue(openssl_pkey_export($generated, $pem));
        $path = self::$sharedDirectory . '/dpop-' . (random_bytes(6) |> bin2hex(...)) . '.pem';
        self::assertSame(strlen($pem), file_put_contents($path, $pem));
        self::assertTrue(chmod($path, 0600));
        $key = new SignedDpopProof($pem);
        [$session] = $this->signIn($this->user, key: $key);

        return [$session, $key, $path];
    }

    /** @param list<string> $roles */
    private function createUser(array $roles): User
    {
        $user = User::createByOperator(
            new Email('password-session-' . (random_bytes(8) |> bin2hex(...)) . '@baander.app'),
            password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'Password session user',
            $roles,
        );
        $this->users->save($user);
        $this->created[] = $user;

        return $user;
    }

    private function issueResetToken(string $token = 'outstanding-reset-token'): void
    {
        $tokens = $this->kernel->getContainer()->get('oauth.acceptance.password_reset_tokens');
        self::assertInstanceOf(PasswordResetTokenRepositoryInterface::class, $tokens);
        $tokens->issue($this->user->getId(), $token, new \DateTimeImmutable('+1 hour'));
    }

    /** Holds the address as the user's pending change, with the token its link would carry. */
    private function requestEmailChange(string $address, string $token): void
    {
        $user = $this->users->findByUuid($this->user->getId());
        self::assertInstanceOf(User::class, $user);
        $user->requestEmailChange(new Email($address));
        $this->users->save($user);
        $tokens = $this->kernel->getContainer()->get('oauth.acceptance.email_verification_tokens');
        self::assertInstanceOf(EmailVerificationTokenRepositoryInterface::class, $tokens);
        $tokens->issue($this->user->getId(), new Email($address), $token, new \DateTimeImmutable('+1 hour'));
    }

    private function assertNoResetToken(): void
    {
        self::assertFalse((bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM password_reset_tokens WHERE user_id = ?)',
            [$this->user->getId()->toString()],
        ));
    }

    /** @param array{accessToken: string, refreshToken: string} $session */
    private function assertStillSignedIn(array $session, SignedDpopProof $key): void
    {
        self::assertSame(200, $this->me($session['accessToken'], $key)->getStatusCode(), 'The access token still authenticates.');
        self::assertSame(200, $this->refresh($session['refreshToken'], $key)->getStatusCode(), 'The refresh token still refreshes.');
    }

    /** @param array{accessToken: string, refreshToken: string} $session */
    private function assertSignedOut(array $session, SignedDpopProof $key): void
    {
        self::assertSame(401, $this->me($session['accessToken'], $key)->getStatusCode(), 'The old access token is rejected.');
        self::assertSame(401, $this->refresh($session['refreshToken'], $key)->getStatusCode(), 'The old refresh token cannot refresh.');
    }

    /** @return array{array{accessToken: string, refreshToken: string}, SignedDpopProof} */
    private function signIn(User $user, string $password = self::PASSWORD, ?SignedDpopProof $key = null): array
    {
        $key ??= new SignedDpopProof();
        $response = $this->send('POST', '/api/auth/login', json: ['email' => $user->getEmail(), 'password' => $password], headers: [
            'DPoP' => $key->createWithNonce('POST', self::ORIGIN . '/api/auth/login', $this->nonce()),
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];

        return [['accessToken' => $data['accessToken'], 'refreshToken' => $data['refreshToken']], $key];
    }

    private function refresh(string $refreshToken, SignedDpopProof $key): Response
    {
        return $this->send('POST', '/api/auth/refresh', json: ['refreshToken' => $refreshToken], headers: [
            'DPoP' => $key->createWithNonce('POST', self::ORIGIN . '/api/auth/refresh', $this->nonce()),
        ]);
    }

    private function me(string $accessToken, SignedDpopProof $key): Response
    {
        return $this->send('GET', '/api/auth/me', $accessToken, $key);
    }

    private function changeOwnPassword(string $accessToken, SignedDpopProof $key, string $password): Response
    {
        return $this->send('PUT', '/api/auth/me/password', $accessToken, $key, [
            'currentPassword' => self::PASSWORD,
            'newPassword' => $password,
        ]);
    }

    /** Nonces are single-use and endpoint-independent; a nonce-less proof yields a fresh challenge. */
    private function nonce(): string
    {
        $probe = new SignedDpopProof();
        $response = $this->send('POST', '/api/auth/refresh', json: ['refreshToken' => 'nonce-probe-' . (random_bytes(8) |> bin2hex(...))], headers: [
            'DPoP' => $probe->create('POST', self::ORIGIN . '/api/auth/refresh', 'no-access-token'),
        ]);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $nonce = $response->headers->get('DPoP-Nonce');
        self::assertIsString($nonce);

        return $nonce;
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, ?string $accessToken = null, ?SignedDpopProof $key = null, ?array $json = null, array $headers = []): Response
    {
        $server = ['REMOTE_ADDR' => $this->ip, 'HTTP_ACCEPT' => 'application/json'];
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $request = Request::create(self::ORIGIN . $path, $method, server: $server, content: $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR));
        if ($accessToken !== null && $key !== null) {
            $request->headers->set('Authorization', 'DPoP ' . $accessToken);
            $request->headers->set('DPoP', $key->create($method, self::ORIGIN . $path, $accessToken));
        }
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }
}
