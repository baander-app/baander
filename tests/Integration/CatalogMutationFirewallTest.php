<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryClaimKind;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Media\Application\Port\StoragePortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Auth\CatalogMutationOAuthKernel;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Redis;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The catalog and library mutation matrix through the production firewall: real RS256 access
 * tokens, ES256 proofs, the Redis replay store and the ORM adapters over a disposable PostgreSQL,
 * with the Messenger transports in memory and the storage port as the only fixtures.
 *
 * Every refusal row asserts the component that refused: the authenticator's code in a 401 body,
 * or the class and message of the security exception a 403 began as, recorded by a
 * kernel-exception probe before the firewall and the API's generic forbidden envelope rewrite
 * it. A refused request leaves no claim, no message on either transport and no storage call; a
 * scan the administrator starts leaves the library claimed with one message on the task
 * transport and nothing on the disposable Redis.
 */
final class CatalogMutationFirewallTest extends TestCase
{
    /** The role voter's refusal, as the attribute listener records it on an administrator route. */
    private const string ROLE_REFUSED = "Access Denied. The user doesn't have ROLE_ADMIN.";
    /** The stream of the `async` transport, where a task message lands without a Swoole server. */
    private const string REDIS_STREAM = 'messages';
    private const int CLAIM_LEASE_SECONDS = 900;

    private static string $directory;
    private static string $privateKey;
    private CatalogMutationOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private UserRepositoryInterface $users;
    private LibraryRepositoryInterface $libraries;
    private RedisClientFactory $redis;
    private TransportInterface $taskTransport;
    private TransportInterface $asyncTransport;
    private StoragePortInterface&MockObject $storage;
    private SignedDpopProof $proof;
    private int $redisEntriesBefore;
    /** @var array{class-string, string}|null the exception the last request was refused with */
    private ?array $refusal = null;
    /** @var list<array{class-string, Uuid}> rows to remove, in creation order */
    private array $rows = [];
    /** @var list<string> temporary library roots to remove */
    private array $roots = [];

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/baander-catalog-mutation-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir(self::$directory, 0700));
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        self::$privateKey = $privateKey;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertSame(strlen($privateKey), file_put_contents(self::$directory . '/private.pem', $privateKey));
        self::assertSame(strlen($details['key']), file_put_contents(self::$directory . '/public.pem', $details['key']));
        self::assertTrue(chmod(self::$directory . '/private.pem', 0600));
        self::assertTrue(chmod(self::$directory . '/public.pem', 0600));
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(self::$directory);
    }

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Disposable PostgreSQL and Redis services are required.');
        }
        $this->kernel = new CatalogMutationOAuthKernel(self::$directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->users = $users;
        $libraries = $container->get(CatalogMutationOAuthKernel::LIBRARIES);
        self::assertInstanceOf(LibraryRepositoryInterface::class, $libraries);
        $this->libraries = $libraries;
        $redis = $container->get('oauth.acceptance.redis_pool');
        self::assertInstanceOf(RedisClientFactory::class, $redis);
        $this->redis = $redis;
        $taskTransport = $container->get(CatalogMutationOAuthKernel::TASK_TRANSPORT);
        self::assertInstanceOf(TransportInterface::class, $taskTransport);
        $this->taskTransport = $taskTransport;
        $asyncTransport = $container->get(CatalogMutationOAuthKernel::ASYNC_TRANSPORT);
        self::assertInstanceOf(TransportInterface::class, $asyncTransport);
        $this->asyncTransport = $asyncTransport;
        $dispatcher = $container->get(CatalogMutationOAuthKernel::DISPATCHER);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        // Above the firewall's exception listener (1), which turns a security refusal into an
        // HTTP exception, and the API's exception subscriber (0), which answers the generic envelope.
        $dispatcher->addListener(KernelEvents::EXCEPTION, function (ExceptionEvent $event): void {
            $throwable = $event->getThrowable();
            $this->refusal = [$throwable::class, $throwable->getMessage()];
        }, 64);
        $this->storage = $this->createMock(StoragePortInterface::class);
        $container->set(StoragePortInterface::class, $this->storage);
        $this->proof = new SignedDpopProof();
        $this->refusal = null;
        $this->rows = [];
        $this->roots = [];
        $this->redisEntriesBefore = $this->redisEntries();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager) && $this->manager->isOpen()) {
            // The claim columns are written by SQL, so a managed library row is stale.
            $this->manager->clear();
            foreach (array_reverse($this->rows) as [$class, $id]) {
                $row = $this->manager->find($class, $id);
                if ($row !== null) {
                    $this->manager->remove($row);
                }
            }
            $this->manager->flush();
            $this->manager->close();
        }
        (new Filesystem())->remove($this->roots);
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        parent::tearDown();
    }

    public function testAdministratorScanClaimsTheLibraryAndQueuesOneMessageInMemory(): void
    {
        $this->storage->expects($this->never())->method($this->anything());
        $jwt = $this->actorToken('admin');
        $library = $this->seedLibrary();
        $path = '/api/libraries/' . $library->getId()->toString() . '/scan';

        $response = $this->request('POST', $path, $jwt);
        self::assertSame(202, $response->getStatusCode(), $this->responseDetails($response));
        self::assertNull($this->refusal);
        $body = $this->json($response);
        self::assertSame($library->getId()->toString(), $body['data']['id'] ?? null);
        self::assertSame('scanning', $body['data']['scanStatus'] ?? null);

        $claim = $this->claimRow($library);
        self::assertSame('scanning', $claim['scan_status']);
        self::assertSame('scan', $claim['claim_kind']);
        self::assertIsString($claim['claim_id']);
        $expiresAt = new \DateTimeImmutable((string) $claim['claim_expires_at']);
        $lease = $expiresAt->getTimestamp() - time();
        self::assertGreaterThan(self::CLAIM_LEASE_SECONDS - 60, $lease, 'The claim lapses fifteen minutes after the scan was queued.');
        self::assertLessThanOrEqual(self::CLAIM_LEASE_SECONDS, $lease);

        self::assertSame(0, $this->redisEntries() - $this->redisEntriesBefore, 'A scan message reached the disposable Redis stream.');
        $sent = $this->sent($this->taskTransport);
        self::assertCount(1, $sent);
        $message = $sent[0];
        self::assertInstanceOf(ScanLibraryCommand::class, $message);
        self::assertSame($library->getSlug(), $message->getLibrarySlug()->toString());
        self::assertSame($claim['claim_id'], $message->getClaimId()?->toString(), 'The queued scan carries the claim it runs under.');
        self::assertFalse($message->isRescan());
        self::assertSame([], $this->sent($this->asyncTransport));

        $second = $this->request('POST', $path, $jwt);
        self::assertSame(409, $second->getStatusCode(), $this->responseDetails($second));
        $conflict = $this->json($second);
        self::assertSame(['reason' => 'library_busy', 'holder' => 'scan'], $conflict['error']['details'] ?? null);
        self::assertSame($claim, $this->claimRow($library), 'A refused second scan leaves the claim as it was.');
        self::assertCount(1, $this->sent($this->taskTransport));
        self::assertSame(0, $this->redisEntries() - $this->redisEntriesBefore);

        $this->releaseScanClaim($library);
    }

    public function testMemberScanIsRefusedByTheRoleVoterWithoutAClaimOrAMessage(): void
    {
        $this->storage->expects($this->never())->method($this->anything());
        $jwt = $this->actorToken('member');
        $library = $this->seedLibrary(member: $this->lastUserId());
        $before = $this->claimRow($library);

        $response = $this->request('POST', '/api/libraries/' . $library->getId()->toString() . '/scan', $jwt);
        self::assertSame(403, $response->getStatusCode(), $this->responseDetails($response));
        self::assertSame([AccessDeniedException::class, self::ROLE_REFUSED], $this->refusal);
        self::assertSame(403, $this->json($response)['error']['code'] ?? null);

        self::assertSame($before, $this->claimRow($library));
        self::assertNull($before['claim_id']);
        self::assertNull($before['scan_status']);
        self::assertSame([], $this->sent($this->taskTransport));
        self::assertSame([], $this->sent($this->asyncTransport));
        self::assertSame(0, $this->redisEntries() - $this->redisEntriesBefore);
    }

    /**
     * Persists a user with a client and a DPoP-bound access token row, signs the token and
     * returns it. An administrator holds ROLE_ADMIN; every other actor holds ROLE_USER alone.
     */
    private function actorToken(string $actor): string
    {
        $user = User::createByOperator(
            new Email('catalog-' . bin2hex(random_bytes(8)) . '@baander.app'),
            'test-only-password',
            'Catalog ' . $actor,
            $actor === 'admin' ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'],
        );
        $this->users->save($user);
        $entity = $this->manager->find(UserEntity::class, $user->getId());
        self::assertInstanceOf(UserEntity::class, $entity);
        $this->rows[] = [UserEntity::class, $entity->getId()];
        $client = new ClientEntity(new PublicId(), 'Disposable client', json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR));
        $token = new AccessTokenEntity((new Uuid())->toString(), $client, $entity, scopes: ['library'], expiresAt: new \DateTimeImmutable('+1 hour'));
        $token->setDpopJkt($this->proof->thumbprint());
        $this->persist($client);
        $this->persist($token);
        $this->manager->flush();

        return OAuthAccessTokenJwt::sign(self::$privateKey, $token);
    }

    /** The id of the user the last actor token was minted for. */
    private function lastUserId(): Uuid
    {
        foreach (array_reverse($this->rows) as [$class, $id]) {
            if ($class === UserEntity::class) {
                return $id;
            }
        }
        self::fail('No actor token was minted.');
    }

    /**
     * Persists a music library, granted to `$member` when given. Its root is a fresh temporary
     * directory holding `$files` (relative path => content) when any are given, and an
     * absent path otherwise. A library's claim is cleared with the row, so a row that claims
     * must release the claim it took before the test ends.
     *
     * @param array<string, string> $files
     */
    private function seedLibrary(?Uuid $member = null, array $files = []): LibraryEntity
    {
        $suffix = bin2hex(random_bytes(8));
        $root = self::$directory . '/root-' . $suffix;
        if ($files !== []) {
            $filesystem = new Filesystem();
            foreach ($files as $relative => $content) {
                $filesystem->dumpFile($root . '/' . $relative, $content);
            }
            $this->roots[] = $root;
        }
        $library = new LibraryEntity('Catalog mutation ' . $suffix, 'catalog-mutation-' . $suffix, $root, 'music', 'local');
        $this->persist($library);
        if ($member !== null) {
            $this->manager->persist(new UserLibraryAccessEntity($member, $library, new \DateTimeImmutable()));
        }
        $this->manager->flush();

        return $library;
    }

    private function persist(LibraryEntity|ClientEntity|AccessTokenEntity $entity): void
    {
        $this->manager->persist($entity);
        $this->rows[] = [$entity::class, $entity->getId()];
    }

    /**
     * The library's claim and scan columns as the database holds them. The identity map is
     * cleared first: the claim statements write these columns by SQL, past any managed row.
     *
     * @return array{scan_status: ?string, claim_id: ?string, claim_kind: ?string, claim_expires_at: ?string}
     */
    private function claimRow(LibraryEntity $library): array
    {
        $this->manager->clear();
        $row = $this->manager->getConnection()->fetchAssociative(
            'SELECT scan_status, claim_id, claim_kind, claim_expires_at FROM libraries WHERE id = :id',
            ['id' => $library->getId()->toString()],
        );
        self::assertIsArray($row);
        /** @var array{scan_status: ?string, claim_id: ?string, claim_kind: ?string, claim_expires_at: ?string} $row */
        return $row;
    }

    /** Ends the scan claim a row took, as an operator would, so the library row can be removed cleanly. */
    private function releaseScanClaim(LibraryEntity $library): void
    {
        self::assertSame(LibraryClaimKind::Scan, $this->libraries->releaseClaim($library->getId(), evenIfLive: true)->released);
        $released = $this->claimRow($library);
        self::assertNull($released['claim_id']);
        self::assertSame('failed', $released['scan_status'], 'A released scan claim marks the scan that never ran as failed.');
    }

    /**
     * The messages on an in-memory transport, which is what the suite's kernel put in place of the real one.
     *
     * @return list<object>
     */
    private function sent(TransportInterface $transport): array
    {
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent());
    }

    /** The length of the async transport's stream on the disposable Redis. */
    private function redisEntries(): int
    {
        return $this->redis->borrow(static fn (Redis $redis): int => (int) $redis->xlen(self::REDIS_STREAM));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function responseDetails(Response $response): string
    {
        if ($response->getStatusCode() < 500) {
            return (string) $response->getContent();
        }
        $details = '';
        foreach (file(self::$directory . '/logs/error.log') ?: [] as $line) {
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (isset($record['message']) && is_string($record['message'])) {
                $details = $record['message'];
            }
        }

        return $details;
    }

    /**
     * Sends a JSON request through the kernel, with a proof from the actor's key when a token is
     * given, and records the exception a refusal began as.
     *
     * @param array<string, mixed> $data
     */
    private function request(string $method, string $path, ?string $jwt = null, array $data = []): Response
    {
        $uri = 'https://baander.app' . $path;
        $request = Request::create($uri, $method, content: $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        if ($jwt !== null) {
            $request->headers->set('Authorization', 'DPoP ' . $jwt);
            $request->headers->set('DPoP', $this->proof->create($method, $uri, $jwt));
        }
        $this->refusal = null;
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }
}
