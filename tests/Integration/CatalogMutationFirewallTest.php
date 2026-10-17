<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryClaimKind;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\StoredFile;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Auth\CatalogMutationOAuthKernel;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Redis;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
 * Every mutation route is crossed with an anonymous request, a library member, a member of no
 * library and an administrator. Every refusal row asserts the component that refused: the
 * authenticator's code in a 401 body, or the class and message of the security exception a 403
 * began as, recorded by a kernel-exception probe before the firewall and the API's generic
 * forbidden envelope rewrite it. A refused request leaves the catalog as it was, no claim, no
 * message on either transport, no storage call and nothing on the disposable Redis; an
 * administrator's request runs to its documented status and leaves the change it names.
 *
 * The route keys are the router's names; the mutation route inventory holds the map to the
 * router, so a new mutation route fails a unit test until it has rows here.
 */
final class CatalogMutationFirewallTest extends TestCase
{
    /** Every catalog and library mutation route this matrix covers, by its router name. */
    public const array ROUTE_KEYS = [
        'album_update',
        'album_merge',
        'album_destroy',
        'song_update',
        'song_destroy',
        'movie_update',
        'movie_destroy',
        'genre_store',
        'genre_update',
        'genre_destroy',
        'genre_add_song',
        'genre_remove_song',
        'genre_add_album',
        'genre_remove_album',
        'album_cover_upload',
        'album_cover_delete',
        'artist_cover_upload',
        'artist_cover_delete',
        'extract_covers_app_catalog_interface_extractcovers__invoke',
        'admin_album_delete',
        'admin_album_delete_preview',
        'admin_song_delete',
        'admin_song_delete_preview',
        'admin_albums_duplicates',
        'library_store',
        'library_update',
        'library_delete',
        'library_scan',
        'library_scan_all',
        'library_validate_path',
    ];

    /** The role voter's refusal, as the attribute listener records it on an administrator route. */
    private const string ROLE_REFUSED = "Access Denied. The user doesn't have ROLE_ADMIN.";
    /** The stream of the `async` transport, where a task message lands without a Swoole server. */
    private const string REDIS_STREAM = 'messages';
    private const int CLAIM_LEASE_SECONDS = 900;
    /** A one-pixel transparent PNG; the cover handler reads its signature and its dimensions. */
    private const string PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    private const string SEED_SLUG_PREFIX = 'catalog-mutation-';

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
    private string $suffix;
    /** @var array{class-string, string}|null the exception the last request was refused with */
    private ?array $refusal = null;
    /** @var list<array{class-string, Uuid}> rows to remove, in creation order */
    private array $rows = [];
    /** @var list<string> temporary library roots, each holding real files */
    private array $roots = [];
    /** @var list<string> other temporary files and directories to remove */
    private array $scratch = [];
    /** @var list<string> access tokens minted in this test */
    private array $tokens = [];
    /** @var list<string> proofs sent in this test */
    private array $proofs = [];
    /** @var list<array{string, string}> path and body of every response */
    private array $bodies = [];

    private LibraryEntity $library;
    private AlbumEntity $album;
    private AlbumEntity $otherAlbum;
    private SongEntity $song;
    private SongEntity $secondSong;
    private SongEntity $otherSong;
    private MovieEntity $movie;
    private GenreEntity $genre;
    private ArtistEntity $artist;

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
        $this->suffix = bin2hex(random_bytes(8));
        $this->refusal = null;
        $this->rows = [];
        $this->roots = [];
        $this->scratch = [];
        $this->tokens = [];
        $this->proofs = [];
        $this->bodies = [];
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
        (new Filesystem())->remove([...$this->roots, ...$this->scratch]);
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        $this->assertNoCredentialInResponsesOrLogs();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function matrix(): iterable
    {
        foreach (self::ROUTE_KEYS as $route) {
            foreach (['anonymous', 'member', 'unrelated', 'admin'] as $actor) {
                yield $route . ' ' . $actor => [$route, $actor];
            }
        }
    }

    #[DataProvider('matrix')]
    public function testEveryMutationIsRefusedBelowAdministratorAndRunsForTheAdministrator(string $route, string $actor): void
    {
        $this->seedCatalog();
        $this->prepare($route);
        $jwt = $actor === 'anonymous' ? null : $this->actorToken($actor);
        if ($actor === 'member') {
            $this->grant($this->lastUserId(), $this->library);
        }
        if ($actor === 'admin') {
            $this->expectStorage($route);
        } else {
            $this->storage->expects($this->never())->method($this->anything());
        }
        $before = $this->state();
        $mutation = $this->mutation($route);

        $response = $this->request($mutation['method'], $mutation['path'], $jwt, $mutation['data'], $mutation['upload'] ?? null);

        if ($actor !== 'admin') {
            if ($actor === 'anonymous') {
                self::assertSame(401, $response->getStatusCode(), $this->responseDetails($response));
                self::assertSame(401, $this->json($response)['error']['code'] ?? null);
            } else {
                self::assertSame(403, $response->getStatusCode(), $this->responseDetails($response));
                self::assertSame([AccessDeniedException::class, self::ROLE_REFUSED], $this->refusal);
                self::assertSame(403, $this->json($response)['error']['code'] ?? null);
            }
            self::assertSame($before, $this->state(), 'A refused mutation must leave the catalog, the libraries and the files as they were.');
            self::assertSame([], $this->sent($this->taskTransport));
            self::assertSame([], $this->sent($this->asyncTransport));
            self::assertSame(0, $this->redisEntries() - $this->redisEntriesBefore);

            return;
        }

        self::assertSame($mutation['status'], $response->getStatusCode(), $this->responseDetails($response));
        self::assertNull($this->refusal);
        self::assertSame(0, $this->redisEntries() - $this->redisEntriesBefore, 'A message reached the disposable Redis stream.');
        if ($mutation['status'] === 204) {
            self::assertSame('', (string) $response->getContent(), 'A 204 carries no body.');
        }
        $this->assertAdministratorEffect($route, $before, $mutation['status'] === 204 ? [] : $this->json($response));
    }

    public function testMemberAndUnrelatedMemberReadTheCatalogWithTheCredentialTheDeniedRowsUse(): void
    {
        $this->storage->expects($this->never())->method($this->anything());
        $this->seedCatalog();

        $member = $this->actorToken('member');
        $this->grant($this->lastUserId(), $this->library);
        $response = $this->request('GET', '/api/albums/' . $this->album->getPublicId()->toString(), $member);
        self::assertSame(200, $response->getStatusCode(), $this->responseDetails($response));
        self::assertSame($this->album->getPublicId()->toString(), $this->json($response)['data']['publicId'] ?? null);
        $response = $this->request('GET', '/api/albums/', $member);
        self::assertSame(200, $response->getStatusCode(), $this->responseDetails($response));

        $unrelated = $this->actorToken('unrelated');
        $response = $this->request('GET', '/api/albums/', $unrelated);
        self::assertSame(200, $response->getStatusCode(), $this->responseDetails($response));
        self::assertIsArray($this->json($response)['data'] ?? null);
        self::assertNull($this->refusal);
    }

    public function testAdministratorScanClaimsTheLibraryAndQueuesOneMessageInMemory(): void
    {
        $this->storage->expects($this->never())->method($this->anything());
        $jwt = $this->actorToken('admin');
        $library = $this->seedLibrary();
        $path = '/api/libraries/' . $library->getPublicId()->toString() . '/scan';

        $response = $this->request('POST', $path, $jwt);
        self::assertSame(202, $response->getStatusCode(), $this->responseDetails($response));
        self::assertNull($this->refusal);
        $body = $this->json($response);
        self::assertSame($library->getPublicId()->toString(), $body['data']['publicId'] ?? null);
        self::assertArrayNotHasKey('id', $body['data']);
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

        $response = $this->request('POST', '/api/libraries/' . $library->getPublicId()->toString() . '/scan', $jwt);
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

    public function testAdministratorScanAllClaimsTheUnclaimedLibrariesAndSkipsTheClaimedOne(): void
    {
        $this->storage->expects($this->never())->method($this->anything());
        $jwt = $this->actorToken('admin');
        $first = $this->seedLibrary();
        $second = $this->seedLibrary();
        $claimed = $this->seedLibrary();
        $heldClaim = new Uuid();
        self::assertTrue($this->libraries->claimScan($claimed->getId(), $heldClaim, self::CLAIM_LEASE_SECONDS)->claimed);
        $heldBefore = $this->claimRow($claimed);

        $response = $this->request('POST', '/api/libraries/scan-all', $jwt);
        self::assertSame(202, $response->getStatusCode(), $this->responseDetails($response));
        self::assertNull($this->refusal);
        $body = $this->json($response);
        // The handler walks every library in the shared database; only the suite's own are known here.
        self::assertGreaterThanOrEqual(2, $body['data']['dispatched'] ?? null);
        self::assertGreaterThanOrEqual(1, $body['data']['skipped'] ?? null);

        $messages = [];
        foreach ($this->sent($this->taskTransport) as $message) {
            self::assertInstanceOf(ScanLibraryCommand::class, $message);
            $messages[$message->getLibrarySlug()->toString()] = $message->getClaimId()?->toString();
        }
        foreach ([$first, $second] as $library) {
            $claim = $this->claimRow($library);
            self::assertSame('scan', $claim['claim_kind'], 'An unclaimed library is claimed by scan-all.');
            self::assertIsString($claim['claim_id']);
            self::assertSame($claim['claim_id'], $messages[$library->getSlug()] ?? null, 'The queued scan carries the claim scan-all took.');
        }
        self::assertSame($heldBefore, $this->claimRow($claimed), 'A claimed library keeps its claim.');
        self::assertArrayNotHasKey($claimed->getSlug(), $messages, 'No scan is queued for a claimed library.');
        self::assertSame([], $this->sent($this->asyncTransport));
        self::assertSame(0, $this->redisEntries() - $this->redisEntriesBefore);

        // Release every claim the row took, the suite's and any other library's in the shared database.
        foreach (array_keys($messages) as $slug) {
            $library = $this->libraries->findBySlug(new LibrarySlug($slug));
            self::assertNotNull($library);
            self::assertSame(LibraryClaimKind::Scan, $this->libraries->releaseClaim($library->getId(), evenIfLive: true)->released);
        }
        self::assertSame(LibraryClaimKind::Scan, $this->libraries->releaseClaim($claimed->getId(), evenIfLive: true)->released);
    }

    public function testAdministratorSongDeletionWithFileRemovalUnlinksTheFileUnderAClaim(): void
    {
        $this->storage->expects($this->never())->method($this->anything());
        $jwt = $this->actorToken('admin');
        $library = $this->seedLibrary(files: ['Artist/Album/one.flac' => 'fLaC one']);
        $album = $this->seedAlbum($library, 'Album on disk');
        $file = $library->getPath() . '/Artist/Album/one.flac';
        $song = $this->seedSong($album, 'Song on disk', $file);
        $path = '/api/admin/songs/' . $song->getPublicId()->toString() . '?deleteFile=true';

        // While a scan holds the library, the delete cannot claim it and touches nothing.
        $heldClaim = new Uuid();
        self::assertTrue($this->libraries->claimScan($library->getId(), $heldClaim, self::CLAIM_LEASE_SECONDS)->claimed);
        $busy = $this->request('DELETE', $path, $jwt);
        self::assertSame(409, $busy->getStatusCode(), $this->responseDetails($busy));
        self::assertFileExists($file);
        self::assertNotNull($this->manager->find(SongEntity::class, $song->getId()));
        self::assertSame($heldClaim->toString(), $this->claimRow($library)['claim_id']);
        self::assertSame(LibraryClaimKind::Scan, $this->libraries->releaseClaim($library->getId(), evenIfLive: true)->released);

        $response = $this->request('DELETE', $path, $jwt);
        self::assertSame(200, $response->getStatusCode(), $this->responseDetails($response));
        self::assertNull($this->refusal);
        $body = $this->json($response);
        self::assertSame(['songs' => 1], $body['data']['deleted'] ?? null);
        self::assertSame(['removed' => [$file], 'missing' => [], 'left' => []], $body['data']['files'] ?? null);
        self::assertFileDoesNotExist($file);
        self::assertNull($this->manager->find(SongEntity::class, $song->getId()));
        $claim = $this->claimRow($library);
        self::assertNull($claim['claim_id'], 'The delete releases its claim.');
        self::assertNull($claim['claim_kind']);
        self::assertSame([], $this->sent($this->taskTransport));
        self::assertSame([], $this->sent($this->asyncTransport));
    }

    public function testAdministratorAlbumDeletionWithFileRemovalUnlinksEveryFileUnderAClaim(): void
    {
        $this->storage->expects($this->never())->method($this->anything());
        $jwt = $this->actorToken('admin');
        $library = $this->seedLibrary(files: ['Artist/Album/one.flac' => 'fLaC one', 'Artist/Album/two.flac' => 'fLaC two']);
        $album = $this->seedAlbum($library, 'Album on disk');
        $files = [$library->getPath() . '/Artist/Album/one.flac', $library->getPath() . '/Artist/Album/two.flac'];
        $songs = [$this->seedSong($album, 'One', $files[0], 1), $this->seedSong($album, 'Two', $files[1], 2)];

        $response = $this->request('DELETE', '/api/admin/albums/' . $album->getPublicId()->toString() . '?deleteFiles=true', $jwt);
        self::assertSame(200, $response->getStatusCode(), $this->responseDetails($response));
        self::assertNull($this->refusal);
        $body = $this->json($response);
        self::assertSame(['albums' => 1, 'songs' => 2, 'coverImages' => 0], $body['data']['deleted'] ?? null);
        self::assertSame(['removed' => $files, 'missing' => [], 'left' => []], $body['data']['files'] ?? null);
        foreach ($files as $file) {
            self::assertFileDoesNotExist($file);
        }
        self::assertNull($this->manager->find(AlbumEntity::class, $album->getId()));
        foreach ($songs as $song) {
            self::assertNull($this->manager->find(SongEntity::class, $song->getId()));
        }
        $claim = $this->claimRow($library);
        self::assertNull($claim['claim_id'], 'The delete releases its claim.');
        self::assertNull($claim['claim_kind']);
    }

    public function testDeletePreviewWithFileInspectionOnAMissingRootRefusesTheDeletionWithoutAClaim(): void
    {
        $this->storage->expects($this->never())->method($this->anything());
        $jwt = $this->actorToken('admin');
        $library = $this->seedLibrary();
        self::assertDirectoryDoesNotExist($library->getPath());
        $album = $this->seedAlbum($library, 'Album without a root');
        $before = $this->claimRow($library);

        $response = $this->request('GET', '/api/admin/albums/' . $album->getPublicId()->toString() . '/delete-preview?deleteFiles=true', $jwt);
        self::assertSame(200, $response->getStatusCode(), $this->responseDetails($response));
        self::assertNull($this->refusal);
        $body = $this->json($response);
        self::assertSame($album->getPublicId()->toString(), $body['data']['album']['id'] ?? null);
        self::assertSame(['allowed' => false, 'libraryBusy' => false, 'files' => []], $body['data']['fileDeletion'] ?? null);
        self::assertSame($before, $this->claimRow($library), 'A preview claims nothing.');
        self::assertNull($before['claim_id']);
        self::assertNotNull($this->manager->find(AlbumEntity::class, $album->getId()));
    }

    public function testAlbumDeletionWithACoverDiscardsTheImageAfterCommit(): void
    {
        $jwt = $this->actorToken('admin');
        $this->seedCatalog();
        $image = $this->seedCover($this->album);
        $connection = $this->manager->getConnection();
        $albumId = $this->album->getId()->toString();
        $imageId = $image->getId()->toString();
        // The discarder logs a failure instead of throwing, so the callback records what it saw
        // and the test asserts it afterwards.
        $seen = [];
        $this->storage->expects($this->never())->method('store');
        $this->storage->expects($this->once())->method('delete')
            ->willReturnCallback(static function (string $path) use ($connection, $albumId, $imageId, &$seen): void {
                $seen = [
                    'path' => $path,
                    'transaction' => $connection->isTransactionActive(),
                    'album' => $connection->fetchOne('SELECT 1 FROM albums WHERE id = :id', ['id' => $albumId]),
                    'image' => $connection->fetchOne('SELECT 1 FROM images WHERE id = :id', ['id' => $imageId]),
                ];
            });
        $this->storage->expects($this->once())->method('deleteDerived')->with($image->getPath(), 'png');

        $response = $this->request('DELETE', '/api/albums/' . $this->album->getPublicId()->toString(), $jwt);
        self::assertSame(200, $response->getStatusCode(), $this->responseDetails($response));
        self::assertNull($this->refusal);
        self::assertSame(['albums' => 1, 'songs' => 2, 'coverImages' => 1], $this->json($response)['data']['deleted'] ?? null);
        self::assertSame(
            ['path' => $image->getPath(), 'transaction' => false, 'album' => false, 'image' => false],
            $seen,
            'The file is discarded after the deleting transaction committed the album and the image row away.',
        );
        $this->manager->clear();
        self::assertNull($this->manager->find(AlbumEntity::class, $this->album->getId()));
        self::assertNull($this->manager->find(ImageEntity::class, $image->getId()));
    }

    public function testAlbumMergeRefusesAlbumsOfDifferentLibraries(): void
    {
        $this->storage->expects($this->never())->method($this->anything());
        $jwt = $this->actorToken('admin');
        $this->seedCatalog();
        $elsewhere = $this->seedAlbum($this->seedLibrary(), 'Album elsewhere');
        $before = $this->state();

        $response = $this->request('POST', '/api/albums/merge', $jwt, [
            'targetPublicId' => $this->album->getPublicId()->toString(),
            'sourcePublicId' => $elsewhere->getPublicId()->toString(),
        ]);
        self::assertSame(400, $response->getStatusCode(), $this->responseDetails($response));
        self::assertSame($before, $this->state());
        self::assertNotNull($this->manager->find(AlbumEntity::class, $elsewhere->getId()));
    }

    public function testValidatePathAnswersForABlankAMissingAndACreatedPath(): void
    {
        $this->storage->expects($this->never())->method($this->anything());
        $jwt = $this->actorToken('admin');
        $path = '/api/libraries/validate-path';

        $blank = $this->request('POST', $path, $jwt, ['path' => '']);
        self::assertSame(422, $blank->getStatusCode(), $this->responseDetails($blank));

        $missing = $this->request('POST', $path, $jwt, ['path' => self::$directory . '/missing-' . $this->suffix]);
        self::assertSame(200, $missing->getStatusCode(), $this->responseDetails($missing));
        $body = $this->json($missing);
        self::assertFalse($body['data']['valid'] ?? null);
        self::assertFalse($body['data']['exists'] ?? null);
        self::assertNull($body['data']['resolvedPath'] ?? null);

        $directory = $this->createDirectory();
        $created = $this->request('POST', $path, $jwt, ['path' => $directory]);
        self::assertSame(200, $created->getStatusCode(), $this->responseDetails($created));
        $body = $this->json($created);
        self::assertTrue($body['data']['valid'] ?? null);
        self::assertSame(realpath($directory), $body['data']['resolvedPath'] ?? null);
        self::assertTrue($body['data']['readable'] ?? null);
    }

    /**
     * Seeds the catalog the matrix mutates: a library with two albums (two songs and one song),
     * a movie, a genre and an artist, all under this test's suffix.
     */
    private function seedCatalog(): void
    {
        $this->library = $this->seedLibrary();
        $this->album = $this->seedAlbum($this->library, 'Seeded album ' . $this->suffix);
        $this->song = $this->seedSong($this->album, 'Seeded song one', $this->library->getPath() . '/one.flac', 1);
        $this->secondSong = $this->seedSong($this->album, 'Seeded song two', $this->library->getPath() . '/two.flac', 2);
        $this->otherAlbum = $this->seedAlbum($this->library, 'Seeded source album ' . $this->suffix);
        $this->otherSong = $this->seedSong($this->otherAlbum, 'Seeded song three', $this->library->getPath() . '/three.flac', 1);
        $this->movie = new MovieEntity(new PublicId(), $this->library, 'Seeded movie ' . $this->suffix);
        $this->genre = new GenreEntity('Seeded genre ' . $this->suffix, self::SEED_SLUG_PREFIX . 'genre-' . $this->suffix);
        $this->artist = new ArtistEntity(new PublicId(), 'Seeded artist ' . $this->suffix);
        foreach ([$this->movie, $this->genre, $this->artist] as $entity) {
            $this->manager->persist($entity);
            $this->rows[] = [$entity::class, $entity->getId()];
        }
        $this->manager->flush();
    }

    /** Puts in place what a row removes: a genre link, a cover, or the directory a path check reads. */
    private function prepare(string $route): void
    {
        switch ($route) {
            case 'genre_remove_song':
                $link = new GenreSongEntity($this->genre, $this->song);
                $this->manager->persist($link);
                $this->rows[] = [GenreSongEntity::class, $link->getId()];
                $this->manager->flush();
                break;
            case 'genre_remove_album':
                $link = new GenreAlbumEntity($this->genre, $this->album);
                $this->manager->persist($link);
                $this->rows[] = [GenreAlbumEntity::class, $link->getId()];
                $this->manager->flush();
                break;
            case 'album_cover_delete':
                $this->seedCover($this->album);
                break;
            case 'artist_cover_delete':
                $this->seedCover($this->artist);
                break;
            case 'library_validate_path':
                $this->createDirectory();
                break;
        }
    }

    /**
     * The request of every route in the matrix, with the administrator's documented status.
     *
     * @return array{method: string, path: string, data: array<string, mixed>, status: int, upload?: UploadedFile}
     */
    private function mutation(string $route): array
    {
        $album = $this->album->getPublicId()->toString();
        $song = $this->song->getPublicId()->toString();
        $genre = '/api/genres/' . $this->genre->getSlug();
        $library = '/api/libraries/' . $this->library->getPublicId()->toString();

        return match ($route) {
            'album_update' => $this->row('PATCH', '/api/albums/' . $album, ['title' => 'Modified album'], 200),
            'album_merge' => $this->row('POST', '/api/albums/merge', ['targetPublicId' => $album, 'sourcePublicId' => $this->otherAlbum->getPublicId()->toString()], 200),
            'album_destroy' => $this->row('DELETE', '/api/albums/' . $album, [], 200),
            'song_update' => $this->row('PATCH', '/api/songs/' . $song, ['title' => 'Modified song'], 200),
            'song_destroy' => $this->row('DELETE', '/api/songs/' . $song, [], 200),
            'movie_update' => $this->row('PATCH', '/api/movies/' . $this->movie->getPublicId()->toString(), ['title' => 'Modified movie'], 200),
            'movie_destroy' => $this->row('DELETE', '/api/movies/' . $this->movie->getPublicId()->toString(), [], 200),
            'genre_store' => $this->row('POST', '/api/genres/', ['name' => 'Created genre ' . $this->suffix, 'slug' => $this->createdSlug()], 201),
            'genre_update' => $this->row('PATCH', $genre, ['name' => 'Modified genre'], 200),
            'genre_destroy' => $this->row('DELETE', $genre, [], 204),
            'genre_add_song' => $this->row('POST', $genre . '/songs', ['songId' => $this->song->getPublicId()->toString()], 204),
            'genre_remove_song' => $this->row('DELETE', $genre . '/songs/' . $this->song->getPublicId()->toString(), [], 204),
            'genre_add_album' => $this->row('POST', $genre . '/albums', ['albumId' => $this->album->getPublicId()->toString()], 204),
            'genre_remove_album' => $this->row('DELETE', $genre . '/albums/' . $this->album->getPublicId()->toString(), [], 204),
            'album_cover_upload' => $this->row('POST', '/api/albums/' . $album . '/cover', [], 200) + ['upload' => $this->pixel()],
            'album_cover_delete' => $this->row('DELETE', '/api/albums/' . $album . '/cover', [], 204),
            'artist_cover_upload' => $this->row('POST', '/api/artists/' . $this->artist->getPublicId()->toString() . '/cover', [], 200) + ['upload' => $this->pixel()],
            'artist_cover_delete' => $this->row('DELETE', '/api/artists/' . $this->artist->getPublicId()->toString() . '/cover', [], 204),
            'extract_covers_app_catalog_interface_extractcovers__invoke' => $this->row('POST', '/api/albums/covers/extract', [], 202),
            'admin_album_delete' => $this->row('DELETE', '/api/admin/albums/' . $album, [], 200),
            'admin_album_delete_preview' => $this->row('GET', '/api/admin/albums/' . $album . '/delete-preview', [], 200),
            'admin_song_delete' => $this->row('DELETE', '/api/admin/songs/' . $song, [], 200),
            'admin_song_delete_preview' => $this->row('GET', '/api/admin/songs/' . $song . '/delete-preview', [], 200),
            'admin_albums_duplicates' => $this->row('GET', '/api/admin/albums/duplicates?libraryId=' . $this->library->getPublicId()->toString(), [], 200),
            'library_store' => $this->row('POST', '/api/libraries', ['name' => 'Created library ' . $this->suffix, 'path' => self::$directory . '/created-' . $this->suffix, 'type' => 'music', 'slug' => $this->createdSlug()], 201),
            'library_update' => $this->row('PATCH', $library, ['name' => 'Modified library'], 200),
            'library_delete' => $this->row('DELETE', $library, [], 200),
            'library_scan' => $this->row('POST', $library . '/scan', [], 202),
            'library_scan_all' => $this->row('POST', '/api/libraries/scan-all', [], 202),
            'library_validate_path' => $this->row('POST', '/api/libraries/validate-path', ['path' => $this->createdDirectory()], 200),
            default => self::fail('No request for route ' . $route),
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{method: string, path: string, data: array<string, mixed>, status: int}
     */
    private function row(string $method, string $path, array $data, int $status): array
    {
        return ['method' => $method, 'path' => $path, 'data' => $data, 'status' => $status];
    }

    /** The storage calls an administrator's row makes; every other row makes none. */
    private function expectStorage(string $route): void
    {
        switch ($route) {
            case 'album_cover_upload':
            case 'artist_cover_upload':
                $this->storage->expects($this->once())->method('store')
                    ->willReturnCallback(static fn (string $source, string $destination): StoredFile => new StoredFile($destination, 'image/png', (int) filesize($source)));
                $this->storage->expects($this->never())->method('delete');
                $this->storage->expects($this->never())->method('deleteDerived');
                break;
            case 'album_cover_delete':
            case 'artist_cover_delete':
                $this->storage->expects($this->never())->method('store');
                $this->storage->expects($this->once())->method('delete');
                $this->storage->expects($this->once())->method('deleteDerived');
                break;
            default:
                $this->storage->expects($this->never())->method($this->anything());
        }
    }

    /**
     * The change an administrator's row leaves, read from the database after the identity map is cleared.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $body
     */
    private function assertAdministratorEffect(string $route, array $before, array $body): void
    {
        $after = $this->state();
        $data = $body['data'] ?? null;
        $albumId = $this->album->getId()->toString();
        $songId = $this->song->getId()->toString();
        $libraryId = $this->library->getId()->toString();

        switch ($route) {
            case 'album_update':
                self::assertSame('Modified album', $after['albums'][$albumId]['title'] ?? null);
                self::assertSame('Modified album', $data['title'] ?? null);
                $this->assertNothingQueued();
                break;
            case 'album_merge':
                self::assertNull($after['albums'][$this->otherAlbum->getId()->toString()], 'The source album is gone.');
                self::assertSame($albumId, $after['songs'][$this->otherSong->getId()->toString()]['album'] ?? null, 'The source song moved to the target.');
                self::assertSame($this->album->getPublicId()->toString(), $data['publicId'] ?? null);
                $this->assertNothingQueued();
                break;
            case 'album_destroy':
            case 'admin_album_delete':
                self::assertNull($after['albums'][$albumId]);
                self::assertNull($after['songs'][$songId]);
                self::assertNull($after['songs'][$this->secondSong->getId()->toString()]);
                self::assertSame(['albums' => 1, 'songs' => 2, 'coverImages' => 0], $data['deleted'] ?? null);
                $this->assertNothingQueued();
                break;
            case 'song_update':
                self::assertSame('Modified song', $after['songs'][$songId]['title'] ?? null);
                self::assertSame('Modified song', $data['title'] ?? null);
                $this->assertNothingQueued();
                break;
            case 'song_destroy':
            case 'admin_song_delete':
                self::assertNull($after['songs'][$songId]);
                self::assertSame(['songs' => 1], $data['deleted'] ?? null);
                self::assertSame(['removed' => [], 'missing' => [], 'left' => []], $data['files'] ?? null);
                $this->assertNothingQueued();
                break;
            case 'movie_update':
                self::assertSame('Modified movie', $after['movie']);
                self::assertSame('Modified movie', $data['title'] ?? null);
                $this->assertNothingQueued();
                break;
            case 'movie_destroy':
                self::assertNull($after['movie']);
                self::assertSame(['movies' => 1, 'videos' => 0], $data['deleted'] ?? null);
                $this->assertNothingQueued();
                break;
            case 'genre_store':
                $this->assertGenreCreated($before, $after, $data);
                $this->assertNothingQueued();
                break;
            case 'genre_update':
                self::assertSame('Modified genre', $after['genre']['name'] ?? null);
                self::assertSame('Modified genre', $data['name'] ?? null);
                $this->assertNothingQueued();
                break;
            case 'genre_destroy':
                self::assertNull($after['genre']);
                $this->assertNothingQueued();
                break;
            case 'genre_add_song':
                self::assertSame([0, 1], [$before['genreSongs'], $after['genreSongs']], 'The song is linked to the genre.');
                $this->assertNothingQueued();
                break;
            case 'genre_remove_song':
                self::assertSame([1, 0], [$before['genreSongs'], $after['genreSongs']], 'The link is gone.');
                $this->assertNothingQueued();
                break;
            case 'genre_add_album':
                self::assertSame([0, 1], [$before['genreAlbums'], $after['genreAlbums']], 'The album is linked to the genre.');
                $this->assertNothingQueued();
                break;
            case 'genre_remove_album':
                self::assertSame([1, 0], [$before['genreAlbums'], $after['genreAlbums']], 'The link is gone.');
                $this->assertNothingQueued();
                break;
            case 'album_cover_upload':
                $this->assertCoverStored('album', $this->album->getId(), $before, $after, $data);
                $this->assertNothingQueued();
                break;
            case 'artist_cover_upload':
                $this->assertCoverStored('artist', $this->artist->getId(), $before, $after, $data);
                $this->assertNothingQueued();
                break;
            case 'album_cover_delete':
                self::assertSame([1, 0], [$before['images']['album'], $after['images']['album']], 'The image row is gone.');
                self::assertIsArray($after['albums'][$albumId]);
                self::assertNull($after['albums'][$albumId]['cover'], 'The album no longer points at a cover.');
                $this->assertNothingQueued();
                break;
            case 'artist_cover_delete':
                self::assertSame([1, 0], [$before['images']['artist'], $after['images']['artist']], 'The image row is gone.');
                self::assertNull($after['artistCover'], 'The artist no longer points at a cover.');
                $this->assertNothingQueued();
                break;
            case 'extract_covers_app_catalog_interface_extractcovers__invoke':
                self::assertSame($before, $after, 'Queueing the extraction changes nothing yet.');
                self::assertIsInt($data['albums'] ?? null);
                $queued = $this->sent($this->asyncTransport);
                self::assertCount(1, $queued);
                self::assertInstanceOf(BatchExtractCoversCommand::class, $queued[0]);
                self::assertSame([], $this->sent($this->taskTransport));
                break;
            case 'admin_album_delete_preview':
                self::assertSame($before, $after, 'A preview changes nothing.');
                self::assertSame($this->album->getPublicId()->toString(), $data['album']['id'] ?? null);
                self::assertSame(2, $data['album']['songCount'] ?? null);
                self::assertIsArray($data);
                self::assertArrayHasKey('fileDeletion', $data);
                self::assertNull($data['fileDeletion'], 'Without file inspection the preview carries no file verdicts.');
                $this->assertNothingQueued();
                break;
            case 'admin_song_delete_preview':
                self::assertSame($before, $after, 'A preview changes nothing.');
                self::assertSame($this->song->getPublicId()->toString(), $data['song']['id'] ?? null);
                self::assertIsArray($data);
                self::assertArrayHasKey('fileDeletion', $data);
                self::assertNull($data['fileDeletion'], 'Without file inspection the preview carries no file verdicts.');
                $this->assertNothingQueued();
                break;
            case 'admin_albums_duplicates':
                self::assertSame($before, $after, 'A listing changes nothing.');
                self::assertIsArray($data);
                $this->assertNothingQueued();
                break;
            case 'library_store':
                $this->assertLibraryCreated($before, $after, $data);
                $this->assertNothingQueued();
                break;
            case 'library_update':
                self::assertSame('Modified library', $after['libraries'][$libraryId]['name'] ?? null);
                self::assertSame('Modified library', $data['name'] ?? null);
                $this->assertNothingQueued();
                break;
            case 'library_delete':
                self::assertNull($after['libraries'][$libraryId]);
                self::assertNull($after['albums'][$albumId], "The library's albums go with it.");
                self::assertSame(['data' => null], $body);
                $this->assertNothingQueued();
                break;
            case 'library_scan':
                $this->assertScanQueued($after);
                break;
            case 'library_scan_all':
                $this->assertScanAllQueued($after, $data);
                break;
            case 'library_validate_path':
                self::assertSame($before, $after, 'A path check changes nothing.');
                self::assertTrue($data['valid'] ?? null);
                self::assertSame(realpath($this->createdDirectory()), $data['resolvedPath'] ?? null);
                $this->assertNothingQueued();
                break;
            default:
                self::fail('No expectation for route ' . $route);
        }
    }

    private function assertNothingQueued(): void
    {
        self::assertSame([], $this->sent($this->taskTransport));
        self::assertSame([], $this->sent($this->asyncTransport));
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed>|null $data
     */
    private function assertGenreCreated(array $before, array $after, ?array $data): void
    {
        $created = $this->manager->getRepository(GenreEntity::class)->findOneBy(['slug' => $this->createdSlug()]);
        self::assertInstanceOf(GenreEntity::class, $created);
        $this->rows[] = [GenreEntity::class, $created->getId()];
        self::assertSame($before['genres'] + 1, $after['genres']);
        self::assertSame($this->createdSlug(), $data['slug'] ?? null);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed>|null $data
     */
    private function assertLibraryCreated(array $before, array $after, ?array $data): void
    {
        $created = $this->manager->getRepository(LibraryEntity::class)->findOneBy(['slug' => $this->createdSlug()]);
        self::assertInstanceOf(LibraryEntity::class, $created);
        // The access row goes with the library.
        $this->rows[] = [LibraryEntity::class, $created->getId()];
        self::assertSame($before['libraryCount'] + 1, $after['libraryCount']);
        self::assertSame($this->createdSlug(), $data['slug'] ?? null);
        $access = $this->manager->getRepository(UserLibraryAccessEntity::class)->findBy(['library' => $created->getId()]);
        self::assertCount(1, $access, 'The creator is granted membership of the new library.');
        self::assertSame($this->lastUserId()->toString(), $access[0]->getUserId()->toString());
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed>|null $data
     */
    private function assertCoverStored(string $owner, Uuid $ownerId, array $before, array $after, ?array $data): void
    {
        self::assertSame(0, $before['images'][$owner]);
        self::assertSame(1, $after['images'][$owner], 'One image row is created.');
        $images = $this->manager->getRepository(ImageEntity::class)->findBy($owner === 'album' ? ['album' => $ownerId] : ['artist' => $ownerId]);
        self::assertCount(1, $images);
        $image = $images[0];
        $this->rows[] = [ImageEntity::class, $image->getId()];
        self::assertStringStartsWith('images/' . $owner . '/' . $ownerId->toString() . '/', $image->getPath());
        self::assertSame('png', $image->getExtension());
        self::assertSame($image->getPublicId()->toString(), $data['publicId'] ?? null);
        self::assertSame(1, $data['width'] ?? null);
        self::assertSame(1, $data['height'] ?? null);
        $cover = $owner === 'album' ? ($after['albums'][$ownerId->toString()]['cover'] ?? null) : $after['artistCover'];
        self::assertSame($image->getId()->toString(), $cover, 'The owner points at the new image.');
    }

    /** @param array<string, mixed> $after */
    private function assertScanQueued(array $after): void
    {
        $claim = $after['libraries'][$this->library->getId()->toString()]['claim'] ?? null;
        self::assertIsArray($claim);
        self::assertSame('scan', $claim['claim_kind']);
        self::assertIsString($claim['claim_id']);
        $sent = $this->sent($this->taskTransport);
        self::assertCount(1, $sent);
        self::assertInstanceOf(ScanLibraryCommand::class, $sent[0]);
        self::assertSame($this->library->getSlug(), $sent[0]->getLibrarySlug()->toString());
        self::assertSame($claim['claim_id'], $sent[0]->getClaimId()?->toString());
        self::assertSame([], $this->sent($this->asyncTransport));
        $this->releaseScanClaim($this->library);
    }

    /**
     * @param array<string, mixed> $after
     * @param array<string, mixed>|null $data
     */
    private function assertScanAllQueued(array $after, ?array $data): void
    {
        $claim = $after['libraries'][$this->library->getId()->toString()]['claim'] ?? null;
        self::assertIsArray($claim);
        self::assertSame('scan', $claim['claim_kind']);
        self::assertGreaterThanOrEqual(1, $data['dispatched'] ?? null);
        $queued = [];
        foreach ($this->sent($this->taskTransport) as $message) {
            self::assertInstanceOf(ScanLibraryCommand::class, $message);
            $queued[$message->getLibrarySlug()->toString()] = $message->getClaimId()?->toString();
        }
        self::assertSame($claim['claim_id'], $queued[$this->library->getSlug()] ?? null);
        self::assertSame([], $this->sent($this->asyncTransport));
        foreach (array_keys($queued) as $slug) {
            $library = $this->libraries->findBySlug(new LibrarySlug($slug));
            self::assertNotNull($library);
            self::assertSame(LibraryClaimKind::Scan, $this->libraries->releaseClaim($library->getId(), evenIfLive: true)->released);
        }
    }

    /**
     * Everything a mutation in the matrix can change, read from the database after the identity
     * map is cleared, and the files under every real root.
     *
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $this->manager->clear();
        $albums = [];
        foreach ([$this->album, $this->otherAlbum] as $seed) {
            $album = $this->manager->find(AlbumEntity::class, $seed->getId());
            $albums[$seed->getId()->toString()] = $album === null ? null : [
                'title' => $album->getTitle(),
                'year' => $album->getYear(),
                'cover' => $album->getCoverImage()?->getId()->toString(),
            ];
        }
        $songs = [];
        foreach ([$this->song, $this->secondSong, $this->otherSong] as $seed) {
            $song = $this->manager->find(SongEntity::class, $seed->getId());
            $songs[$seed->getId()->toString()] = $song === null ? null : [
                'title' => $song->getTitle(),
                'album' => $song->getAlbum()->getId()->toString(),
                'path' => $song->getPath(),
            ];
        }
        $genre = $this->manager->find(GenreEntity::class, $this->genre->getId());
        $libraries = [];
        $libraryIds = [];
        foreach ($this->rows as [$class, $id]) {
            if ($class !== LibraryEntity::class) {
                continue;
            }
            $library = $this->manager->find(LibraryEntity::class, $id);
            $libraries[$id->toString()] = $library === null ? null : [
                'name' => $library->getName(),
                'claim' => $this->claimRow($library),
            ];
            $libraryIds[] = $id;
        }
        $files = [];
        foreach ($this->roots as $root) {
            $found = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $found[] = (string) $file;
            }
            sort($found);
            $files[$root] = $found;
        }
        $images = $this->manager->getRepository(ImageEntity::class);
        $connection = $this->manager->getConnection();

        return [
            'albums' => $albums,
            'songs' => $songs,
            'movie' => $this->manager->find(MovieEntity::class, $this->movie->getId())?->getTitle(),
            'genre' => $genre === null ? null : ['name' => $genre->getName(), 'slug' => $genre->getSlug()],
            'genreSongs' => $this->manager->getRepository(GenreSongEntity::class)->count(['genre' => $this->genre->getId()]),
            'genreAlbums' => $this->manager->getRepository(GenreAlbumEntity::class)->count(['genre' => $this->genre->getId()]),
            'genres' => (int) $connection->fetchOne('SELECT count(*) FROM genres WHERE slug LIKE :prefix', ['prefix' => self::SEED_SLUG_PREFIX . '%']),
            'artistCover' => $this->manager->find(ArtistEntity::class, $this->artist->getId())?->getCoverImage()?->getId()->toString(),
            'images' => [
                'album' => $images->count(['album' => $this->album->getId()]),
                'artist' => $images->count(['artist' => $this->artist->getId()]),
            ],
            'libraries' => $libraries,
            'libraryCount' => (int) $connection->fetchOne('SELECT count(*) FROM libraries WHERE slug LIKE :prefix', ['prefix' => self::SEED_SLUG_PREFIX . '%']),
            'access' => $libraryIds === [] ? 0 : (int) $connection->fetchOne(
                'SELECT count(*) FROM user_library_access WHERE library_id IN (:ids)',
                ['ids' => array_map(static fn (Uuid $id): string => $id->toString(), $libraryIds)],
                ['ids' => ArrayParameterType::STRING],
            ),
            'files' => $files,
        ];
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
        $jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $token);
        $this->tokens[] = $jwt;

        return $jwt;
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

    /** Grants a user membership of a library; the row goes with the library. */
    private function grant(Uuid $user, LibraryEntity $library): void
    {
        $managed = $this->manager->find(LibraryEntity::class, $library->getId());
        self::assertInstanceOf(LibraryEntity::class, $managed);
        $this->manager->persist(new UserLibraryAccessEntity($user, $managed, new \DateTimeImmutable()));
        $this->manager->flush();
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
        $library = new LibraryEntity('Catalog mutation ' . $suffix, self::SEED_SLUG_PREFIX . $suffix, $root, 'music', 'local');
        $this->persist($library);
        if ($member !== null) {
            $this->manager->persist(new UserLibraryAccessEntity($member, $library, new \DateTimeImmutable()));
        }
        $this->manager->flush();

        return $library;
    }

    private function seedAlbum(LibraryEntity $library, string $title): AlbumEntity
    {
        $managed = $this->manager->find(LibraryEntity::class, $library->getId());
        self::assertInstanceOf(LibraryEntity::class, $managed);
        $album = new AlbumEntity(new PublicId(), $managed, $title, 'album');
        $this->persist($album);
        $this->manager->flush();

        return $album;
    }

    private function seedSong(AlbumEntity $album, string $title, string $path, int $track = 1): SongEntity
    {
        $managed = $this->manager->find(AlbumEntity::class, $album->getId());
        self::assertInstanceOf(AlbumEntity::class, $managed);
        $song = new SongEntity(new PublicId(), $managed, $title, $path, strlen($title), 'audio/flac');
        $song->setTrack($track);
        $song->setHash(hash('sha256', $path));
        $this->persist($song);
        $this->manager->flush();

        return $song;
    }

    /** Persists a one-pixel cover image row for an album or an artist and points the owner at it. */
    private function seedCover(AlbumEntity|ArtistEntity $owner): ImageEntity
    {
        $kind = $owner instanceof AlbumEntity ? 'album' : 'artist';
        $managed = $this->manager->find($owner::class, $owner->getId());
        self::assertNotNull($managed);
        $image = new ImageEntity('images/' . $kind . '/' . $owner->getId()->toString() . '/seeded.png', 'png', 'image/png', new PublicId(), 68, 1, 1, $kind);
        if ($managed instanceof AlbumEntity) {
            $image->setAlbum($managed);
        } else {
            $image->setArtist($managed);
        }
        $this->manager->persist($image);
        $this->rows[] = [ImageEntity::class, $image->getId()];
        $managed->setCoverImage($image);
        $this->manager->flush();

        return $image;
    }

    /** A one-pixel PNG as the browser would upload it. */
    private function pixel(): UploadedFile
    {
        $path = self::$directory . '/pixel-' . bin2hex(random_bytes(4)) . '.png';
        self::assertSame(68, file_put_contents($path, base64_decode(self::PIXEL_PNG, true)));
        $this->scratch[] = $path;

        return new UploadedFile($path, 'cover.png', 'image/png', null, true);
    }

    /** Creates the directory the path rows validate, once per test. */
    private function createDirectory(): string
    {
        $directory = $this->createdDirectory();
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0700));
            $this->scratch[] = $directory;
        }

        return $directory;
    }

    private function createdDirectory(): string
    {
        return self::$directory . '/directory-' . $this->suffix;
    }

    private function createdSlug(): string
    {
        return self::SEED_SLUG_PREFIX . 'created-' . $this->suffix;
    }

    private function persist(LibraryEntity|ClientEntity|AccessTokenEntity|AlbumEntity|SongEntity $entity): void
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
     * No response body and no log file of the kernel carries an access token or a proof the
     * test minted.
     */
    private function assertNoCredentialInResponsesOrLogs(): void
    {
        $secrets = [...$this->tokens, ...$this->proofs];
        foreach ($this->bodies as [$path, $body]) {
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $body, 'The response body of ' . $path . ' carries a credential.');
            }
        }
        foreach (glob(self::$directory . '/logs/*') ?: [] as $file) {
            $content = (string) file_get_contents($file);
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $content, basename($file) . ' carries a credential.');
            }
        }
    }

    /**
     * Sends a request through the kernel, with a proof from the actor's key when a token is
     * given, and records the exception a refusal began as. A JSON body carries `$data`; an
     * upload goes as a multipart form with the file in the `cover` field.
     *
     * @param array<string, mixed> $data
     */
    private function request(string $method, string $path, ?string $jwt = null, array $data = [], ?UploadedFile $upload = null): Response
    {
        $uri = 'https://baander.app' . $path;
        if ($upload !== null) {
            $request = Request::create($uri, $method, files: ['cover' => $upload]);
        } else {
            $request = Request::create($uri, $method, content: $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR));
            $request->headers->set('Content-Type', 'application/json');
        }
        if ($jwt !== null) {
            // The proof binds the URI without its query, as the validator compares it.
            $proof = $this->proof->create($method, 'https://baander.app' . explode('?', $path, 2)[0], $jwt);
            $this->proofs[] = $proof;
            $request->headers->set('Authorization', 'DPoP ' . $jwt);
            $request->headers->set('DPoP', $proof);
        }
        $this->refusal = null;
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        $this->bodies[] = [$path, (string) $response->getContent()];

        return $response;
    }
}
