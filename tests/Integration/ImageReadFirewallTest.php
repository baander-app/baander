<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Media\Application\Port\ImageConversionPortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Media\Infrastructure\Storage\FlysystemStorage;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\MediaOAuthKernel;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Production OAuth/DPoP and actual PostgreSQL authorization, with disposable image files. */
final class ImageReadFirewallTest extends TestCase
{
    private static string $directory;
    private static string $privateKey;
    private MediaOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private UserRepositoryInterface $users;
    private UserEntity $member;
    private LibraryEntity $allowedLibrary;
    private AlbumEntity $deniedAlbum;
    private ArtistEntity $allowedArtist;
    private PlaylistEntity $deniedPlaylist;
    /** @var array<string, ImageEntity> */
    private array $images = [];
    /** @var list<UserEntity|LibraryEntity|AlbumEntity|ArtistEntity|PlaylistEntity|ImageEntity|ClientEntity|AccessTokenEntity> */
    private array $entities = [];
    private int $storageReads = 0;
    private ?\Closure $onStorageRead = null;
    private SignedDpopProof $proof;
    private ?string $jwt = null;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/baander-image-firewall-' . bin2hex(random_bytes(8));
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
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
        self::assertNotFalse($gif);
        self::assertSame(strlen($gif), file_put_contents(self::$directory . '/image.gif', $gif));
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
        $this->kernel = new MediaOAuthKernel(self::$directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->users = $users;
        $storage = $this->createStub(StoragePortInterface::class);
        $storage->method('resolve')->willReturnCallback(function (): string {
            ++$this->storageReads;
            if ($this->onStorageRead !== null) {
                ($this->onStorageRead)();
            }

            return self::$directory . '/image.gif';
        });
        $converter = $this->createMock(ImageConversionPortInterface::class);
        $converter->expects(self::never())->method('convertPreset');
        $converter->expects(self::never())->method('convertToWebp');
        $container->set(StoragePortInterface::class, $storage);
        $container->set(ImageConversionPortInterface::class, $converter);

        $this->member = $this->createUser('member');
        $foreignOwner = $this->createUser('foreign');
        $suffix = bin2hex(random_bytes(8));
        $this->allowedLibrary = new LibraryEntity('Image allowed', 'image-allowed-' . $suffix, '/tmp/image-allowed-' . $suffix, 'music', 'local');
        $deniedLibrary = new LibraryEntity('Image denied', 'image-denied-' . $suffix, '/tmp/image-denied-' . $suffix, 'music', 'local');
        $album = new AlbumEntity(new PublicId(), $this->allowedLibrary, 'Allowed image album', 'album');
        $this->deniedAlbum = new AlbumEntity(new PublicId(), $deniedLibrary, 'Denied image album', 'album');
        $this->allowedArtist = new ArtistEntity(new PublicId(), 'Allowed image artist');
        $deniedArtist = new ArtistEntity(new PublicId(), 'Denied image artist');
        $playlist = new PlaylistEntity(new PublicId(), $this->member, 'Owned image playlist');
        $this->deniedPlaylist = new PlaylistEntity(new PublicId(), $foreignOwner, 'Foreign public image playlist');
        $this->deniedPlaylist->setPublic(true);
        foreach ([$this->allowedLibrary, $deniedLibrary, $album, $this->deniedAlbum, $this->allowedArtist, $deniedArtist, $playlist, $this->deniedPlaylist] as $entity) {
            $this->persist($entity);
        }
        $this->manager->persist(new ArtistAlbumEntity($this->allowedArtist, $album, 'primary'));
        $this->manager->persist(new ArtistAlbumEntity($deniedArtist, $this->deniedAlbum, 'primary'));
        $this->manager->persist(new UserLibraryAccessEntity($this->member, $this->allowedLibrary, new \DateTimeImmutable()));

        foreach (['album', 'artist', 'reverse-album', 'reverse-artist', 'playlist', 'mixed', 'denied-album', 'denied-artist', 'denied-playlist', 'orphan'] as $name) {
            $image = new ImageEntity('internal/' . $this->deniedAlbum->getId() . '/' . $name . '.gif', 'gif', 'image/gif', new PublicId(), 42, 1, 1, 'album');
            $image->setBlurhash('fixture-hash');
            $this->images[$name] = $image;
            $this->persist($image);
        }
        $this->images['album']->setAlbum($album);
        $this->images['artist']->setArtist($this->allowedArtist);
        $album->setCoverImage($this->images['reverse-album']);
        $this->allowedArtist->setCoverImage($this->images['reverse-artist']);
        $this->images['playlist']->setPlaylist($playlist);
        $this->images['mixed']->setAlbum($this->deniedAlbum);
        $this->images['mixed']->setArtist($this->allowedArtist);
        $this->images['mixed']->setPlaylist($this->deniedPlaylist);
        $this->images['denied-album']->setAlbum($this->deniedAlbum);
        $this->images['denied-artist']->setArtist($deniedArtist);
        $this->images['denied-playlist']->setPlaylist($this->deniedPlaylist);
        $this->manager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager) && $this->manager->isOpen()) {
            $this->manager->clear();
            foreach (array_reverse($this->entities) as $entity) {
                $managed = $this->manager->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->manager->remove($managed);
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

    /** @return iterable<string, array{string, string}> */
    public static function actorsAndRoutes(): iterable
    {
        foreach (['anonymous', 'member', 'unrelated', 'admin'] as $actor) {
            foreach (['', '/file', '/blurhash'] as $suffix) {
                yield $actor . ($suffix === '' ? '/metadata' : $suffix) => [$actor, $suffix];
            }
        }
    }

    #[DataProvider('actorsAndRoutes')]
    public function testImageReadAuthorization(string $actor, string $suffix): void
    {
        $this->authenticate($actor);
        foreach ($this->images as $name => $image) {
            $allowed = $actor === 'admin' || ($actor === 'member' && in_array($name, ['album', 'artist', 'reverse-album', 'reverse-artist', 'playlist', 'mixed'], true));
            $expectedStatus = $actor === 'anonymous' ? 401 : ($allowed ? 200 : 404);
            $reads = $this->storageReads;
            $response = $this->request($image, $suffix);
            self::assertSame($expectedStatus, $response->getStatusCode(), $actor . '/' . $name . $suffix);
            if (!$allowed) {
                self::assertSame($reads, $this->storageReads, 'Denied reads must not resolve storage.');
                continue;
            }
            self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            if ($suffix === '/file') {
                self::assertInstanceOf(BinaryFileResponse::class, $response);
                self::assertSame(self::$directory . '/image.gif', $response->getFile()->getPathname());
                self::assertSame($reads + 1, $this->storageReads);
            } elseif ($suffix === '') {
                $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                self::assertArrayNotHasKey('path', $data['data']);
                self::assertSame($image->getPublicId()->toString(), $data['data']['publicId']);
            } else {
                $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('fixture-hash', $data['data']['blurhash']);
            }
        }
    }

    public function testProjectionHidesDeniedOwnerIdsWithoutChangingStoredAssociations(): void
    {
        $this->authenticate('member');
        $response = $this->request($this->images['mixed']);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertNull($data['albumId']);
        self::assertNull($data['playlistId']);
        self::assertSame($this->allowedArtist->getId()->toString(), $data['artistId']);
        self::assertArrayNotHasKey('path', $data);
        $imageId = $this->images['mixed']->getId();
        $albumId = $this->deniedAlbum->getId();
        $playlistId = $this->deniedPlaylist->getId();
        $this->manager->flush();
        $this->manager->clear();
        $image = $this->manager->find(ImageEntity::class, $imageId);
        self::assertInstanceOf(ImageEntity::class, $image);
        self::assertSame($albumId->toString(), $image->getAlbum()?->getId()->toString());
        self::assertSame($playlistId->toString(), $image->getPlaylist()?->getId()->toString());
    }

    public function testPresetSymlinkOutsideStorageRootServesOnlyOriginalImage(): void
    {
        $this->authenticate('member');
        $image = $this->images['album'];
        [$storageRoot, $originalPath] = $this->configureRealStorage($image);
        $presetPath = $this->derivedPath($storageRoot, $image->getPath(), 'thumb');
        $privatePath = self::$directory . '/private-preset.webp';
        $privateContents = 'private-derived-content';
        self::assertSame(strlen($privateContents), file_put_contents($privatePath, $privateContents));
        self::assertTrue(symlink($privatePath, $presetPath));

        $response = $this->request($image, '/file?preset=thumb');

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame($originalPath, $response->getFile()->getPathname());
        self::assertSame('private-derived-content', file_get_contents($privatePath));
    }

    public function testWebpSymlinkOutsideStorageRootServesOnlyOriginalImage(): void
    {
        $this->authenticate('member');
        $image = new ImageEntity(
            'internal/' . $this->allowedLibrary->getId() . '/authorized-source.jpg',
            'jpg',
            'image/jpeg',
            new PublicId(),
            42,
            1,
            1,
            'album',
        );
        $image->setAlbum($this->images['album']->getAlbum());
        $this->persist($image);
        $this->manager->flush();
        [$storageRoot, $originalPath] = $this->configureRealStorage($image);
        $webpPath = $this->derivedPath($storageRoot, $image->getPath());
        $privatePath = self::$directory . '/private-webp.webp';
        $privateContents = 'private-webp-content';
        self::assertSame(strlen($privateContents), file_put_contents($privatePath, $privateContents));
        self::assertTrue(symlink($privatePath, $webpPath));

        $response = $this->request($image, '/file');

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame($originalPath, $response->getFile()->getPathname());
        self::assertSame('private-webp-content', file_get_contents($privatePath));
    }

    public function testExistingPresetInsideStorageRootIsServed(): void
    {
        $this->authenticate('member');
        $image = $this->images['album'];
        [$storageRoot] = $this->configureRealStorage($image);
        $presetPath = $this->derivedPath($storageRoot, $image->getPath(), 'small');
        $derivedContents = 'authorized-derived-content';
        self::assertSame(strlen($derivedContents), file_put_contents($presetPath, $derivedContents));

        $response = $this->request($image, '/file?preset=small');

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame($presetPath, $response->getFile()->getPathname());
    }

    public function testRevocationImmediatelyDeniesAllImageReadRoutes(): void
    {
        $this->authenticate('member');
        self::assertSame(200, $this->request($this->images['reverse-album'])->getStatusCode());
        $access = $this->kernel->getContainer()->get('media.acceptance.library_access');
        self::assertInstanceOf(LibraryAccessPortInterface::class, $access);
        $access->revoke($this->member->getId(), $this->allowedLibrary->getId());
        foreach (['', '/file', '/blurhash'] as $suffix) {
            self::assertSame(404, $this->request($this->images['reverse-album'], $suffix)->getStatusCode());
        }
        self::assertSame(0, $this->storageReads);
        foreach (['', '/file', '/blurhash'] as $suffix) {
            self::assertSame(200, $this->request($this->images['playlist'], $suffix)->getStatusCode());
        }
    }

    public function testMissingBlurhashIsComputedAndPersistedForAuthorizedImage(): void
    {
        $this->authenticate('member');
        $image = $this->images['album'];
        $image->setBlurhash(null);
        $this->manager->flush();
        $response = $this->request($image, '/blurhash');
        self::assertSame(200, $response->getStatusCode());
        $hash = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['blurhash'];
        self::assertIsString($hash);
        self::assertNotSame('', $hash);
        $this->manager->clear();
        $stored = $this->manager->find(ImageEntity::class, $image->getId());
        self::assertInstanceOf(ImageEntity::class, $stored);
        self::assertSame($hash, $stored->getBlurhash());
    }

    public function testDeniedMissingBlurhashDoesNotReadStorageOrWriteHash(): void
    {
        $this->authenticate('member');
        $image = $this->images['denied-album'];
        $image->setBlurhash(null);
        $this->manager->flush();
        self::assertSame(404, $this->request($image, '/blurhash')->getStatusCode());
        self::assertSame(404, $this->request($image, '/file?preset=thumb')->getStatusCode());
        self::assertSame(0, $this->storageReads);
        $this->manager->clear();
        $stored = $this->manager->find(ImageEntity::class, $image->getId());
        self::assertInstanceOf(ImageEntity::class, $stored);
        self::assertNull($stored->getBlurhash());
    }

    public function testImageRemovedDuringHashGenerationReturns404WithoutComputedHash(): void
    {
        $this->authenticate('member');
        $image = $this->images['album'];
        $image->setBlurhash(null);
        $this->manager->flush();
        $this->onStorageRead = function () use ($image): void {
            $this->manager->createQuery('DELETE FROM ' . ImageEntity::class . ' image WHERE image.id = :id')
                ->setParameter('id', $image->getId())
                ->execute();
        };

        $response = $this->request($image, '/blurhash');
        self::assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('data', $data);
        self::assertSame(1, $this->storageReads);
    }

    private function createUser(string $actor): UserEntity
    {
        $user = User::createByOperator(new Email('image-' . $actor . '-' . bin2hex(random_bytes(8)) . '@baander.app'), 'unused-password', 'Image acceptance', $actor === 'admin' ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER']);
        $this->users->save($user);
        $entity = $this->manager->find(UserEntity::class, $user->getId());
        self::assertInstanceOf(UserEntity::class, $entity);
        $this->entities[] = $entity;

        return $entity;
    }

    /** @param UserEntity|LibraryEntity|AlbumEntity|ArtistEntity|PlaylistEntity|ImageEntity $entity */
    private function persist(object $entity): void
    {
        $this->manager->persist($entity);
        $this->entities[] = $entity;
    }

    private function authenticate(string $actor): void
    {
        if ($actor === 'anonymous') {
            return;
        }
        $entity = $actor === 'member' ? $this->member : $this->createUser($actor);
        $client = new ClientEntity(new PublicId(), 'Image acceptance client', json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR));
        $this->proof = new SignedDpopProof();
        $token = new AccessTokenEntity((new Uuid())->toString(), $client, $entity, scopes: ['library'], expiresAt: new \DateTimeImmutable('+1 hour'));
        $token->setDpopJkt($this->proof->thumbprint());
        $this->manager->persist($client);
        $this->manager->persist($token);
        $this->entities[] = $client;
        $this->entities[] = $token;
        $this->manager->flush();
        $this->jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $token);
    }

    private function request(ImageEntity $image, string $suffix = ''): Response
    {
        // Mirror the request boundary: fixtures must not leave a mutable image managed.
        $this->manager->clear();
        $uri = 'https://baander.app/api/images/' . $image->getPublicId() . $suffix;
        $request = Request::create($uri);
        if ($this->jwt !== null) {
            $request->headers->set('Authorization', 'DPoP ' . $this->jwt);
            $request->headers->set('DPoP', $this->proof->create('GET', explode('?', $uri, 2)[0], $this->jwt));
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }

    /** @return array{string, string} */
    private function configureRealStorage(ImageEntity $image): array
    {
        $storageRoot = self::$directory . '/storage-root-' . bin2hex(random_bytes(8));
        $originalPath = $storageRoot . '/' . $image->getPath();
        self::assertTrue(mkdir(dirname($originalPath), 0700, true));
        $contents = file_get_contents(self::$directory . '/image.gif');
        self::assertNotFalse($contents);
        self::assertSame(strlen($contents), file_put_contents($originalPath, $contents));
        $this->kernel->getContainer()->set(StoragePortInterface::class, new FlysystemStorage($storageRoot));

        return [$storageRoot, $originalPath];
    }

    private function derivedPath(string $storageRoot, string $sourcePath, ?string $preset = null): string
    {
        $directory = pathinfo($sourcePath, PATHINFO_DIRNAME);
        $name = pathinfo($sourcePath, PATHINFO_FILENAME);
        $filename = $preset === null ? $name . '.webp' : $name . '_' . $preset . '.webp';

        return $storageRoot . '/' . ($directory === '.' ? '' : $directory . '/') . $filename;
    }
}
