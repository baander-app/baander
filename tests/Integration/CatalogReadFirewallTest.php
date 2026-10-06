<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreMovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\CatalogOAuthKernel;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Domain\ReadModel\GenreReadView;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Real production OAuth/DPoP firewall, ORM catalog adapters, and disposable PostgreSQL/Redis. */
final class CatalogReadFirewallTest extends TestCase
{
    private static string $directory;
    private static string $privateKey;
    private CatalogOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private UserRepositoryInterface $users;
    /** @var array<string, array{artist: ArtistEntity, album: AlbumEntity, song: SongEntity, movie: MovieEntity, library: LibraryEntity}> */
    private array $catalog = [];
    /** @var array<string, GenreEntity> */
    private array $genres = [];
    private ArtistEntity $sharedArtist;
    private ArtistEntity $unlinkedArtist;
    private SignedDpopProof $proof;
    private string $jwt;
    private Uuid $actorId;
    private ?\Throwable $lastException = null;
    /** @var list<array{class: class-string, id: Uuid}> */
    private array $cleanup = [];

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$directory)) {
            (new Filesystem())->remove(self::$directory);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Disposable PostgreSQL and Redis services are required.');
        }
        if (!isset(self::$directory)) {
            self::$directory = sys_get_temp_dir() . '/baander-catalog-read-' . bin2hex(random_bytes(8));
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

        $this->kernel = new CatalogOAuthKernel(self::$directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        self::assertSame('prod', $container->getParameter('kernel.environment'));
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $dispatcher = $container->get('catalog.acceptance.dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::EXCEPTION, function (ExceptionEvent $event): void {
            $this->lastException = $event->getThrowable();
        }, 2048);
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->users = $users;

        $suffix = bin2hex(random_bytes(8));
        foreach (['allowed', 'denied'] as $visibility) {
            $library = new LibraryEntity('Catalog ' . $visibility, 'catalog-' . $visibility . '-' . $suffix, '/tmp/catalog-' . $visibility . '-' . $suffix, 'music', 'local');
            $label = $visibility === 'denied' ? 'A denied' : 'Z allowed';
            $album = new AlbumEntity(new PublicId(), $library, 'Acceptance ' . $label . ' album', 'album');
            $song = new SongEntity(new PublicId(), $album, 'Acceptance ' . $label . ' song', '/tmp/' . $visibility . '.flac', 1, 'audio/flac');
            $artist = new ArtistEntity(new PublicId(), 'Acceptance ' . $label . ' artist');
            $movie = new MovieEntity(new PublicId(), $library, 'Acceptance ' . $label . ' movie');
            $image = new ImageEntity('/tmp/catalog-' . $visibility . '.jpg', 'jpg', 'image/jpeg', new PublicId(), 1, 1, 1, 'album');
            $this->persistFixture($image, $image->getId());
            $album->setCoverImage($image);
            $this->catalog[$visibility] = compact('library', 'album', 'song', 'artist', 'movie');
            foreach ([$library, $album, $song, $artist, $movie] as $entity) {
                $this->persistFixture($entity, $entity->getId());
            }
            $this->manager->persist(new ArtistSongEntity($artist, $song, 'primary'));
            $this->manager->persist(new ArtistAlbumEntity($artist, $album, 'primary'));
        }
        $this->sharedArtist = new ArtistEntity(new PublicId(), 'Acceptance shared artist');
        $this->unlinkedArtist = new ArtistEntity(new PublicId(), 'Acceptance unlinked artist');
        $this->persistFixture($this->sharedArtist, $this->sharedArtist->getId());
        $this->persistFixture($this->unlinkedArtist, $this->unlinkedArtist->getId());
        foreach ($this->catalog as $entities) {
            $this->manager->persist(new ArtistSongEntity($this->sharedArtist, $entities['song'], 'featured'));
        }
        $this->seedGenres();
        $this->manager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager) && $this->manager->isOpen()) {
            $this->manager->clear();
            foreach (array_reverse($this->cleanup) as $identity) {
                $entity = $this->manager->find($identity['class'], $identity['id']);
                if ($entity !== null) {
                    $this->manager->remove($entity);
                }
            }
            $this->manager->flush();
            $this->manager->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function lists(): iterable
    {
        foreach (['artists', 'albums', 'songs', 'movies'] as $kind) {
            foreach (['member', 'unrelated', 'admin'] as $actor) {
                foreach (['', 'Acceptance'] as $query) {
                    yield $kind . ':' . $actor . ':' . ($query === '' ? 'plain' : 'search') => [$kind, $actor, $query];
                }
            }
        }
    }

    #[DataProvider('lists')]
    public function testCatalogListsFilterBeforeCountsAndPagination(string $kind, string $actor, string $query): void
    {
        $this->authenticate($actor);
        $path = '/api/' . $kind . '/?' . http_build_query(['q' => $query, 'limit' => 1]);
        $response = $this->request($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $expectedTotal = $actor === 'unrelated' ? 0 : ($actor === 'admin' ? ($kind === 'artists' ? 4 : 2) : ($kind === 'artists' ? 2 : 1));
        self::assertSame($expectedTotal, $body['meta']['total']);
        self::assertCount(min(1, $expectedTotal), $body['data']);
        if ($actor === 'member') {
            $ids = array_column($body['data'], 'publicId');
            self::assertNotContains($this->catalog['denied'][rtrim($kind, 's')]->getPublicId()->toString(), $ids);
            self::assertNotContains($this->unlinkedArtist->getPublicId()->toString(), $ids);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function details(): iterable
    {
        foreach (['artist', 'album', 'song', 'movie'] as $kind) {
            foreach (['member', 'unrelated', 'admin'] as $actor) {
                yield $kind . ':' . $actor => [$kind, $actor];
            }
        }
    }

    #[DataProvider('details')]
    public function testCatalogDetailsRespectLibraryMembership(string $kind, string $actor): void
    {
        $this->authenticate($actor);
        foreach ($this->catalog as $visibility => $entities) {
            $response = $this->request('/api/' . $kind . 's/' . $entities[$kind]->getPublicId());
            $allowed = $actor === 'admin' || ($actor === 'member' && $visibility === 'allowed');
            self::assertSame($allowed ? 200 : 404, $response->getStatusCode(), (string) $response->getContent());
        }
    }

    public function testAlbumSongsDuplicatesAndSharedArtistsDoNotExposeDeniedMedia(): void
    {
        $this->authenticate('member');
        $allowed = $this->catalog['allowed'];
        $denied = $this->catalog['denied'];
        $response = $this->request('/api/albums/' . $allowed['album']->getPublicId());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([$allowed['song']->getPublicId()->toString()], array_column($body['data']['songs'], 'publicId'));
        self::assertSame(404, $this->request('/api/albums/' . $denied['album']->getPublicId() . '/duplicates')->getStatusCode());
        self::assertSame(200, $this->request('/api/albums/' . $allowed['album']->getPublicId() . '/duplicates')->getStatusCode());
        self::assertSame(404, $this->request('/api/albums/' . $denied['album']->getPublicId() . '/cover')->getStatusCode());
        self::assertSame(302, $this->request('/api/albums/' . $allowed['album']->getPublicId() . '/cover')->getStatusCode());
        self::assertSame(200, $this->request('/api/artists/' . $this->sharedArtist->getPublicId())->getStatusCode());
        self::assertSame(404, $this->request('/api/artists/' . $this->unlinkedArtist->getPublicId())->getStatusCode());
    }

    public function testDeniedSearchAndForgedFiltersCannotWidenTheScope(): void
    {
        $this->authenticate('member');
        foreach (['artists', 'albums', 'songs', 'movies'] as $kind) {
            $response = $this->request('/api/' . $kind . '/?' . http_build_query(['q' => 'denied']));
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0, $body['meta']['total']);
            self::assertSame([], $body['data']);
        }
        $response = $this->request('/api/albums/?' . http_build_query(['q' => 'Acceptance', 'artistId' => $this->catalog['denied']['artist']->getPublicId()->toString()]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['meta']['total']);
        self::assertSame([], $body['data']);
    }

    public function testSongCursorCannotResumeIntoAnInaccessibleLibrary(): void
    {
        $this->authenticate('admin');
        $first = $this->request('/api/songs/?limit=1');
        self::assertSame(200, $first->getStatusCode());
        $body = json_decode((string) $first->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsString($body['meta']['next_cursor']);
        $cursor = $body['meta']['next_cursor'];
        $this->authenticate('member');
        $response = $this->request('/api/songs/?' . http_build_query(['limit' => 1, 'cursor' => $cursor]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['meta']['total']);
        self::assertNotContains($this->catalog['denied']['song']->getPublicId()->toString(), array_column($body['data'], 'publicId'));
    }

    public function testAnonymousCatalogReadsRequireAuthentication(): void
    {
        foreach (['artists', 'albums', 'songs', 'movies', 'genres'] as $kind) {
            self::assertSame(401, $this->request('/api/' . $kind . '/')->getStatusCode());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function libraryActors(): iterable
    {
        yield 'member' => ['member'];
        yield 'unrelated' => ['unrelated'];
        yield 'admin' => ['admin'];
    }

    #[DataProvider('libraryActors')]
    public function testLibraryListTypeDetailsAndStatisticsUseRealOAuthMembership(string $actor): void
    {
        $this->authenticate($actor);
        $expected = match ($actor) {
            'member' => [$this->catalog['allowed']['library']->getId()->toString()],
            'admin' => array_map(static fn (array $entities): string => $entities['library']->getId()->toString(), $this->catalog),
            default => [],
        };
        sort($expected);
        foreach (['/api/libraries', '/api/libraries?type=music'] as $path) {
            $response = $this->request($path);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $ids = array_column($body['data'], 'id');
            sort($ids);
            self::assertSame($expected, $ids);
        }
        foreach ($this->catalog as $visibility => $entities) {
            $allowed = $actor === 'admin' || ($actor === 'member' && $visibility === 'allowed');
            $path = '/api/libraries/' . $entities['library']->getId();
            foreach ([$path, $path . '/stats'] as $route) {
                $response = $this->request($route);
                self::assertSame($allowed ? 200 : 404, $response->getStatusCode(), (string) $response->getContent());
            }
            if ($allowed) {
                $stats = json_decode((string) $this->request($path . '/stats')->getContent(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(1, $stats['data']['songs']);
                self::assertSame(1, $stats['data']['albums']);
            }
        }
    }

    public function testMembershipRevocationImmediatelyHidesCatalogAndLibraryReads(): void
    {
        $this->authenticate('member');
        $libraryId = $this->catalog['allowed']['library']->getId();
        $path = '/api/libraries/' . $libraryId;
        self::assertSame(200, $this->request($path)->getStatusCode());
        $access = $this->kernel->getContainer()->get('catalog.acceptance.library_access');
        self::assertInstanceOf(LibraryAccessPortInterface::class, $access);
        $access->revoke($this->actorId, $libraryId);

        self::assertSame(404, $this->request($path)->getStatusCode());
        self::assertSame(404, $this->request($path . '/stats')->getStatusCode());
        $response = $this->request('/api/libraries');
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
        $response = $this->request('/api/genres/?flat=true');
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
        foreach (['artist', 'album', 'song', 'movie'] as $kind) {
            $response = $this->request('/api/' . $kind . 's/' . $this->catalog['allowed'][$kind]->getPublicId());
            self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
            $response = $this->request('/api/' . $kind . 's/');
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0, $body['meta']['total']);
            self::assertSame([], $body['data']);
        }
    }

    public function testScopedApplicationPortsRejectMixedLibraryMetadataAndEmptyScope(): void
    {
        $container = $this->kernel->getContainer();
        $albums = $container->get('catalog.acceptance.albums');
        $genres = $container->get('catalog.acceptance.genres');
        $artists = $container->get('catalog.acceptance.artists');
        $movies = $container->get('catalog.acceptance.movies');
        $songs = $container->get('catalog.acceptance.songs');
        self::assertInstanceOf(AlbumPortInterface::class, $albums);
        self::assertInstanceOf(GenrePortInterface::class, $genres);
        self::assertInstanceOf(ArtistPortInterface::class, $artists);
        self::assertInstanceOf(MoviePortInterface::class, $movies);
        self::assertInstanceOf(SongPortInterface::class, $songs);
        $allowed = $this->catalog['allowed'];
        $denied = $this->catalog['denied'];
        $scope = LibraryReadScope::restricted([$allowed['library']->getId()]);
        $none = LibraryReadScope::none();

        self::assertSame(3, $genres->countVisible($scope));
        self::assertSame(0, $genres->countVisible($none));
        self::assertNull($genres->findVisibleByUuid($this->genres['allowed-root']->getId(), $none));
        self::assertSame([], $genres->findVisibleChildren($this->genres['denied-parent']->getId(), $scope));
        $projected = $genres->findVisibleByUuid($this->genres['allowed-movie']->getId(), $scope);
        self::assertInstanceOf(GenreReadView::class, $projected);
        self::assertNull($projected->getParent());
        self::assertSame(1, $albums->countVisible($scope));
        self::assertSame(2, $artists->countVisible($scope));
        self::assertSame(1, $movies->countVisible($scope));
        self::assertSame(1, $songs->countVisible($scope));
        self::assertSame(0, $albums->countVisible($none));
        self::assertSame(0, $artists->countVisible($none));
        self::assertSame(0, $movies->countVisible($none));
        self::assertSame(0, $songs->countVisible($none));
        self::assertNull($albums->findVisibleByUuid($allowed['album']->getId(), $none));
        self::assertNull($artists->findVisibleByUuid($allowed['artist']->getId(), $none));
        self::assertNull($movies->findVisibleByUuid($allowed['movie']->getId(), $none));
        self::assertNull($songs->findVisibleByUuid($allowed['song']->getId(), $none));
        self::assertNull($albums->findVisibleWithSongs($denied['album']->getId(), $scope));
        self::assertSame([], $albums->getVisibleArtistNamesForAlbum($denied['album']->getId(), $scope));
        $names = $songs->getVisibleArtistNamesForSongs([$allowed['song']->getId(), $denied['song']->getId()], $scope);
        self::assertArrayHasKey($allowed['song']->getId()->toString(), $names);
        self::assertArrayNotHasKey($denied['song']->getId()->toString(), $names);
        $titles = $songs->getVisibleAlbumTitlesByIds([$allowed['album']->getId(), $denied['album']->getId()], $scope);
        self::assertArrayHasKey($allowed['album']->getId()->toString(), $titles);
        self::assertArrayNotHasKey($denied['album']->getId()->toString(), $titles);
        self::assertSame([], $songs->getVisibleArtistNamesForSongs([$allowed['song']->getId()], $none));
        self::assertSame([], $songs->getVisibleAlbumTitlesByIds([$allowed['album']->getId()], $none));
    }

    #[DataProvider('libraryActors')]
    public function testGenreListsAndChildrenRespectDirectLibraryAssociations(string $actor): void
    {
        $this->authenticate($actor);
        $visible = match ($actor) {
            'admin' => array_keys($this->genres),
            'member' => ['allowed-root', 'allowed-song', 'allowed-movie'],
            default => [],
        };
        $roots = match ($actor) {
            'admin' => ['allowed-root', 'denied-parent', 'orphan'],
            'member' => ['allowed-root', 'allowed-movie'],
            default => [],
        };
        foreach ([false, true] as $flat) {
            $response = $this->request('/api/genres/?' . http_build_query(['flat' => $flat ? 'true' : 'false']));
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $expected = array_map(fn (string $key): string => $this->genres[$key]->getId()->toString(), $flat ? $visible : $roots);
            $ids = array_column($body['data'], 'uuid');
            sort($expected);
            sort($ids);
            self::assertSame($expected, $ids);
            if ($actor === 'member') {
                $promoted = array_filter($body['data'], fn (array $genre): bool => $genre['uuid'] === $this->genres['allowed-movie']->getId()->toString());
                self::assertCount(1, $promoted);
                self::assertNull(array_values($promoted)[0]['parentId']);
            }
        }
        foreach ($this->genres as $key => $genre) {
            $response = $this->request('/api/genres/' . $genre->getSlug());
            $allowed = in_array($key, $visible, true);
            self::assertSame($allowed ? 200 : 404, $response->getStatusCode(), (string) $response->getContent());
            if ($allowed && $key === 'allowed-root') {
                $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
                $children = array_column($body['data']['children'], 'uuid');
                $expectedChildren = [$this->genres['allowed-song']->getId()->toString()];
                if ($actor === 'admin') {
                    $expectedChildren[] = $this->genres['denied-child']->getId()->toString();
                }
                sort($children);
                sort($expectedChildren);
                self::assertSame($expectedChildren, $children);
            }
        }
    }

    public function testGenreHiddenParentProjectionPreservesStoredHierarchy(): void
    {
        $this->authenticate('member');
        $child = $this->genres['allowed-movie'];
        $response = $this->request('/api/genres/' . $child->getSlug());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($body['data']['parentId']);
        $this->manager->flush();
        $this->manager->clear();
        $stored = $this->manager->find(GenreEntity::class, $child->getId());
        self::assertInstanceOf(GenreEntity::class, $stored);
        self::assertSame($this->genres['denied-parent']->getId()->toString(), $stored->getParent()?->getId()->toString());
    }

    private function seedGenres(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $root = new GenreEntity('Permitted root', 'allowed-root-' . $suffix);
        $deniedParent = new GenreEntity('Private parent', 'denied-parent-' . $suffix);
        $this->genres = [
            'allowed-root' => $root,
            'allowed-song' => new GenreEntity('Permitted song genre', 'allowed-song-' . $suffix, $root),
            'allowed-movie' => new GenreEntity('Permitted movie genre', 'allowed-movie-' . $suffix, $deniedParent),
            'denied-child' => new GenreEntity('Private child', 'denied-child-' . $suffix, $root),
            'denied-parent' => $deniedParent,
            'orphan' => new GenreEntity('Unlinked genre', 'orphan-' . $suffix),
        ];
        foreach ($this->genres as $genre) {
            $this->persistFixture($genre, $genre->getId());
        }
        $this->manager->persist(new GenreAlbumEntity($root, $this->catalog['allowed']['album']));
        $this->manager->persist(new GenreSongEntity($this->genres['allowed-song'], $this->catalog['allowed']['song']));
        $this->manager->persist(new GenreMovieEntity($this->genres['allowed-movie'], $this->catalog['allowed']['movie']));
        $this->manager->persist(new GenreAlbumEntity($this->genres['denied-child'], $this->catalog['denied']['album']));
        $this->manager->persist(new GenreMovieEntity($deniedParent, $this->catalog['denied']['movie']));
    }

    private function persistFixture(object $entity, Uuid $id): void
    {
        $this->manager->persist($entity);
        $this->cleanup[] = ['class' => $entity::class, 'id' => $id];
    }

    private function authenticate(string $actor): void
    {
        $user = User::createByOperator(new Email('catalog-' . bin2hex(random_bytes(8)) . '@baander.app'), 'unused-password', 'Catalog acceptance', $actor === 'admin' ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER']);
        $this->users->save($user);
        $this->actorId = $user->getId();
        $entity = $this->manager->find(UserEntity::class, $user->getId());
        self::assertInstanceOf(UserEntity::class, $entity);
        $client = new ClientEntity(new PublicId(), 'Catalog acceptance client', json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR));
        $this->proof = new SignedDpopProof();
        $token = new AccessTokenEntity((new Uuid())->toString(), $client, $entity, scopes: ['library'], expiresAt: new \DateTimeImmutable('+1 hour'));
        $token->setDpopJkt($this->proof->thumbprint());
        if ($actor === 'member') {
            $this->manager->persist(new UserLibraryAccessEntity($entity, $this->catalog['allowed']['library'], new \DateTimeImmutable()));
        }
        $this->persistFixture($entity, $entity->getId());
        $this->persistFixture($client, $client->getId());
        $this->persistFixture($token, $token->getId());
        $this->manager->flush();
        $this->jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $token);
    }

    private function assertNoUnhandledException(Response $response): void
    {
        if ($response->getStatusCode() === 500 && $this->lastException !== null) {
            self::fail($this->lastException::class . ': ' . $this->lastException->getMessage());
        }
    }

    private function request(string $path): Response
    {
        $uri = 'https://baander.app' . $path;
        $request = Request::create($uri);
        if (isset($this->jwt)) {
            $request->headers->set('Authorization', 'DPoP ' . $this->jwt);
            $proofUri = 'https://baander.app' . parse_url($uri, PHP_URL_PATH);
            $request->headers->set('DPoP', $this->proof->create('GET', $proofUri, $this->jwt));
        }
        $this->lastException = null;
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        $this->assertNoUnhandledException($response);

        return $response;
    }
}
