<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\PushedAuthorizationRequest;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\PushedAuthorizationRequestEntity;
use App\Auth\Infrastructure\Doctrine\UserRepository;
use App\Auth\Infrastructure\Repository\OAuth\AuthCodeRepository;
use App\Auth\Infrastructure\Repository\OAuth\ClientRepository;
use App\Auth\Infrastructure\Repository\OAuth\PushedAuthorizationRequestRepository;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use App\Tests\Fixtures\Library\LibraryScanClaimRace;
use App\Tests\Support\FinalSchemaBootstrap;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Pushed authorization requests on PostgreSQL, in a schema built by the application migrations.
 *
 * Each statement commits on its own, and a second session sees what the first committed, so
 * single use is decided by the database and not by a shared test transaction.
 */
final class OAuthPushedAuthorizationRequestPersistenceTest extends TestCase
{
    private const string REDIRECT = 'https://app.baander.app/callback';
    // RFC 7636 appendix B.
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    // RFC 7638 section 3.1.
    private const string THUMBPRINT = 'NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs';

    private string $schema;
    private Connection $writer;
    private Connection $contender;
    private EntityManager $manager;
    private PushedAuthorizationRequestRepository $requests;
    private PushedAuthorizationRequestRepository $contendingRequests;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        CustomTypesRegistrar::register();
        $this->schema = 'oauth_par_test_' . bin2hex(random_bytes(8));
        $this->writer = LibraryScanClaimRace::connect('public');
        self::assertStringStartsWith('18.', (string) $this->writer->fetchOne('SHOW server_version'));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->writer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        FinalSchemaBootstrap::bootstrap($this->writer);
        $this->contender = LibraryScanClaimRace::connect($this->schema);

        $this->manager = self::entityManager($this->writer);
        $this->requests = new PushedAuthorizationRequestRepository($this->manager);
        $this->contendingRequests = new PushedAuthorizationRequestRepository(self::entityManager($this->contender));
    }

    /** The Auth mappings over one session; the contender process builds its own the same way. */
    public static function entityManager(Connection $connection): EntityManager
    {
        CustomTypesRegistrar::register();
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Auth/Infrastructure/Doctrine/Entity'], isDevMode: true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->enableNativeLazyObjects(true);

        return new EntityManager($connection, $config);
    }

    protected function tearDown(): void
    {
        if (isset($this->contender)) {
            $this->contender->close();
        }
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            $this->writer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->writer->close();
        }
    }

    public function testAPushedRequestRoundTripsAndALookupDoesNotUseItUp(): void
    {
        $client = $this->createClient();
        $pushedAt = new DateTimeImmutable('2026-11-19T12:00:00.123456+00:00');
        $request = $this->push($client, $pushedAt);

        $found = $this->contendingRequests->findByRequestId($request->getRequestId());

        self::assertNotNull($found);
        self::assertTrue($request->getId()->equals($found->getId()));
        self::assertSame($request->requestUri(), $found->requestUri());
        self::assertTrue($client->equals($found->getClientId()));
        self::assertSame(self::REDIRECT, $found->getRedirectUri());
        self::assertSame(['library', 'playlist'], $found->getScopeIdentifiers());
        self::assertSame('state-123', $found->getState());
        self::assertSame(self::CHALLENGE, $found->getCodeChallenge());
        self::assertSame('S256', $found->getCodeChallengeMethod());
        self::assertSame(self::THUMBPRINT, $found->getDpopJkt());
        self::assertEquals($pushedAt, $found->getCreatedAt(), 'Microseconds survive.');
        self::assertEquals($pushedAt->modify('+300 seconds'), $found->getExpiresAt());
        self::assertFalse($found->isConsumed());
        self::assertNull($this->contendingRequests->findByRequestId(PushedAuthorizationRequest::create(
            $client, self::REDIRECT, [], null, self::CHALLENGE, 'S256', self::THUMBPRINT, new DateInterval('PT300S'), new DateTimeImmutable(),
        )->getRequestId()), 'A request that was never pushed is unknown.');
    }

    /** A request the user already decided on cannot be decided on again. */
    public function testARequestIsUsedUpOnce(): void
    {
        $client = $this->createClient();
        $request = $this->push($client);

        self::assertTrue($this->requests->consume($request->getRequestId(), $client));
        self::assertFalse($this->contendingRequests->consume($request->getRequestId(), $client), 'The second session finds it used up.');
        self::assertTrue($this->contendingRequests->findByRequestId($request->getRequestId())?->isConsumed());
    }

    public function testAnExpiredRequestIsNotUsedUp(): void
    {
        $client = $this->createClient();
        $request = $this->push($client, new DateTimeImmutable('-301 seconds'));

        self::assertFalse($this->requests->consume($request->getRequestId(), $client));
        self::assertNull($this->consumedAt($request));
    }

    public function testAnotherClientCannotUseARequestUp(): void
    {
        $client = $this->createClient();
        $request = $this->push($client);

        self::assertFalse($this->requests->consume($request->getRequestId(), $this->createClient()));
        self::assertNull($this->consumedAt($request));
        self::assertTrue($this->requests->consume($request->getRequestId(), $client), 'The pushing client still can.');
    }

    public function testTwoConcurrentDecisionsHaveExactlyOneWinner(): void
    {
        $client = $this->createClient();
        $request = $this->push($client);

        // The first decision holds its row lock until it commits; the second waits on it.
        $this->writer->beginTransaction();
        self::assertTrue($this->requests->consume($request->getRequestId(), $client));
        $contender = $this->startContender($request, $client);
        LibraryScanClaimRace::awaitLockWait($this->writer, $contender);
        $this->writer->commit();

        self::assertSame(['consumed' => false], LibraryScanClaimRace::finish($contender, $this->writer));
        self::assertNotNull($this->consumedAt($request));
    }

    public function testDeletingClientsWithLoadedRequestsLetsLaterFlushesSucceed(): void
    {
        $clients = [$this->createClient(), $this->createClient()];
        $requests = array_map(fn (Uuid $client): PushedAuthorizationRequest => $this->push($client), $clients);
        $loaded = [];
        foreach ($clients as $index => $client) {
            $clientEntity = $this->manager->find(ClientEntity::class, $client);
            $requestEntity = $this->manager->find(PushedAuthorizationRequestEntity::class, $requests[$index]->getId());
            self::assertNotNull($clientEntity);
            self::assertSame($clientEntity, $requestEntity?->getClient());
            $loaded[] = $clientEntity;
        }

        // The database deletes each client's requests; the second flush must not trip over the
        // loaded request of the first client.
        foreach ($loaded as $clientEntity) {
            $this->manager->remove($clientEntity);
            $this->manager->flush();
        }

        foreach ($requests as $request) {
            self::assertNull($this->contendingRequests->findByRequestId($request->getRequestId()));
        }
    }

    public function testThePurgeDeletesRequestsExpiredMoreThanTheCutoffAgo(): void
    {
        $client = $this->createClient();
        $cutoff = new DateTimeImmutable('-1 hour');
        $stale = $this->push($client, $cutoff->modify('-301 seconds'));
        $staleAndUsed = $this->push($client, $cutoff->modify('-301 seconds'));
        $this->writer->executeStatement('UPDATE oauth_pushed_authorization_requests SET consumed_at = created_at WHERE id = ?', [$staleAndUsed->getId()->toString()]);
        $recentlyExpired = $this->push($client, new DateTimeImmutable('-30 minutes'));
        $usable = $this->push($client);

        self::assertSame(2, $this->requests->deleteExpiredBefore($cutoff));

        self::assertNull($this->requests->findByRequestId($stale->getRequestId()));
        self::assertNull($this->requests->findByRequestId($staleAndUsed->getRequestId()));
        self::assertNotNull($this->requests->findByRequestId($recentlyExpired->getRequestId()));
        self::assertNotNull($this->requests->findByRequestId($usable->getRequestId()));
    }

    public function testAnAuthorizationCodeKeepsItsKeyThumbprint(): void
    {
        $user = User::register(new Email('par-' . bin2hex(random_bytes(4)) . '@baander.app'), 'hashed', 'Alice');
        new UserRepository(
            $this->manager,
            $this->createStub(PasswordResetTokenRepositoryInterface::class),
            $this->createStub(EmailVerificationTokenRepositoryInterface::class),
        )->save($user);
        $client = Client::create('Thumbprint player', [self::REDIRECT]);
        new ClientRepository($this->manager, new JsonEncoder())->saveClient($client);
        $codes = new AuthCodeRepository($this->manager, new JsonEncoder());
        $code = AuthCode::create($user, $client, self::REDIRECT, self::CHALLENGE, 'S256', [new Scope('library')], new DateInterval('PT60S'), self::THUMBPRINT);

        $codes->save($code);
        $this->manager->clear();

        self::assertSame(self::THUMBPRINT, $codes->findByCodeId($code->getCodeId())?->getDpopJkt());
    }

    private function createClient(): Uuid
    {
        $id = Uuid::generate();
        $this->writer->insert('oauth_clients', [
            'id' => $id->toString(),
            'public_id' => (new PublicId())->toString(),
            'name' => 'Pushing client',
            'redirect' => json_encode([self::REDIRECT], JSON_THROW_ON_ERROR),
            'created_at' => '2026-11-19 12:00:00+00',
            'updated_at' => '2026-11-19 12:00:00+00',
        ]);

        return $id;
    }

    private function push(Uuid $client, ?DateTimeImmutable $pushedAt = null): PushedAuthorizationRequest
    {
        $request = PushedAuthorizationRequest::create(
            clientId: $client,
            redirectUri: self::REDIRECT,
            scopes: [new Scope('library'), new Scope('playlist')],
            state: 'state-123',
            codeChallenge: self::CHALLENGE,
            codeChallengeMethod: 'S256',
            dpopJkt: self::THUMBPRINT,
            lifetime: new DateInterval('PT300S'),
            now: $pushedAt ?? new DateTimeImmutable(),
        );
        $this->requests->save($request);

        return $request;
    }

    /** @return array{process: resource, output: resource, application: string} */
    private function startContender(PushedAuthorizationRequest $request, Uuid $client): array
    {
        $application = 'oauth_par_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        self::assertNotFalse($output);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/OAuth/pushed-request-consume.php', $this->schema, $application, $request->getRequestId()->toString(), $client->toString()],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        self::assertIsResource($process);

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    private function consumedAt(PushedAuthorizationRequest $request): mixed
    {
        return $this->contender->fetchOne('SELECT consumed_at FROM oauth_pushed_authorization_requests WHERE id = ?', [$request->getId()->toString()]);
    }
}
