<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\FirstPartyOAuthClient;
use App\Tests\Fixtures\Auth\MusicBrainzMatchOAuthKernel;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\FakeMusicBrainzLookup;
use App\Tests\Unit\Catalog\Application\Service\MusicBrainzMatch\MusicBrainzReleaseFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MusicBrainz match routes and the server-file metadata routes through the production OAuth
 * and DPoP firewall: a library member who is not an administrator, even one with access to the
 * album's library, is refused searching candidates, comparing, applying a match, and extracting or
 * matching a server file, before MusicBrainz is asked or anything is written. An administrator's
 * search and comparison reach MusicBrainz, which is a fake.
 */
final class MusicBrainzMatchFirewallTest extends TestCase
{
    use MusicBrainzReleaseFixtures;

    private const string RELEASE_ID = '0e1ea5e0-0000-4000-8000-000000000001';

    private static string $directory;
    private static string $privateKey;
    private MusicBrainzMatchOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private UserRepositoryInterface $users;
    private FakeMusicBrainzLookup $musicBrainz;
    private UserEntity $member;
    private AlbumEntity $album;
    private MusicBrainzRelease $release;
    /** @var list<UserEntity|LibraryEntity|AlbumEntity|ArtistEntity|ArtistAlbumEntity|SongEntity|ArtistSongEntity|ClientEntity|AccessTokenEntity> every row the test stored, removed again in reverse order */
    private array $entities = [];
    private SignedDpopProof $proof;
    private ?string $jwt = null;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/baander-match-firewall-' . (random_bytes(8) |> bin2hex(...));
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
        new Filesystem()->remove(self::$directory);
    }

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Disposable PostgreSQL and Redis services are required.');
        }
        $this->kernel = new MusicBrainzMatchOAuthKernel(self::$directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->users = $users;
        $this->musicBrainz = new FakeMusicBrainzLookup();
        $container->set(MusicBrainzLookupInterface::class, $this->musicBrainz);
        $this->member = $this->createUser('member');

        $suffix = random_bytes(8) |> bin2hex(...);
        $this->release = self::release(self::RELEASE_ID, [2]);
        $library = new LibraryEntity('Match firewall', 'match-firewall-' . $suffix, '/tmp/match-firewall-' . $suffix, 'music', 'local');
        $this->album = new AlbumEntity(new PublicId(), $library, self::ALBUM_TITLE . ' ' . $suffix, 'album');
        $artist = new ArtistEntity(new PublicId(), self::ALBUM_ARTIST . ' ' . $suffix);
        foreach ([$library, $this->album, $artist] as $entity) {
            $this->persist($entity);
        }
        $this->persist(new ArtistAlbumEntity($artist, $this->album, 'primary', $artist->getName()));
        foreach ($this->release->media[0]->tracks as $track) {
            $song = new SongEntity(new PublicId(), $this->album, $track->title, sprintf('/private/match-firewall-%s/%d.flac', $suffix, $track->position), 1, 'audio/flac');
            $song->setTrack($track->position);
            $song->setDisc(1);
            $song->setLength(($track->lengthMs ?? 0) / 1000);
            $this->persist($song);
            $this->persist(new ArtistSongEntity($artist, $song, 'primary', $artist->getName()));
        }
        // Removed with the library.
        $this->manager->persist(new UserLibraryAccessEntity($this->member->getId(), $library, new \DateTimeImmutable()));
        $this->manager->flush();
        $this->musicBrainz->releases[$this->release->id] = $this->release;
        $this->musicBrainz->releaseSearches[$this->album->getTitle() . '|' . $artist->getName()] = [self::releaseSummary($this->release)];
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
    public static function nonAdminsAndOperations(): iterable
    {
        foreach (['member', 'unrelated', 'anonymous'] as $actor) {
            foreach (['candidates', 'comparison', 'apply', 'extract', 'match'] as $operation) {
                yield $actor . '/' . $operation => [$actor, $operation];
            }
        }
    }

    #[DataProvider('nonAdminsAndOperations')]
    public function testANonAdministratorIsRefusedBeforeMusicBrainzOrTheCatalog(string $actor, string $operation): void
    {
        if ($actor !== 'anonymous') {
            $this->authenticate($actor);
        }

        $response = $this->operation($operation);

        self::assertSame($actor === 'anonymous' ? 401 : 403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([], $this->musicBrainz->calls);
        $this->manager->clear();
        self::assertNull($this->manager->find(AlbumEntity::class, $this->album->getId())?->getReleaseMbid());
    }

    public function testAnAdministratorSearchesAndComparesThroughTheFirewall(): void
    {
        $this->authenticate('admin');

        $candidates = $this->operation('candidates');
        self::assertSame(200, $candidates->getStatusCode(), (string) $candidates->getContent());
        self::assertSame([$this->release->id], array_column(self::data($candidates)['candidates'], 'id'));

        $comparison = $this->operation('comparison');
        self::assertSame(200, $comparison->getStatusCode(), (string) $comparison->getContent());
        self::assertSame($this->release->id, self::data($comparison)['candidateId']);
    }

    private function operation(string $operation): Response
    {
        $path = '/api/admin/metadata/match/album/' . $this->album->getPublicId()->toString();

        return match ($operation) {
            'candidates' => $this->request($path . '/candidates'),
            'comparison' => $this->request($path . '/comparison?candidate=' . $this->release->id),
            'apply' => $this->request($path . '/apply', 'POST', ['candidate' => $this->release->id, 'fields' => [['key' => 'releaseMbid', 'current' => null]]]),
            'extract' => $this->request('/api/metadata/extract', 'POST', ['path' => '/private/match-firewall.flac']),
            'match' => $this->request('/api/metadata/match', 'POST', ['path' => '/private/match-firewall.flac', 'candidates' => [['title' => 'Track 1-1']]]),
            default => throw new \LogicException(sprintf('No operation "%s".', $operation)),
        };
    }

    /** The release as a release search lists it. */
    private static function releaseSummary(MusicBrainzRelease $release): MusicBrainzReleaseSummary
    {
        return new MusicBrainzReleaseSummary(
            id: $release->id,
            title: $release->title,
            score: 100,
            artistCredit: $release->artistCredit,
            date: $release->date,
            country: $release->country,
            status: $release->status,
            disambiguation: $release->disambiguation,
            barcode: $release->barcode,
            labels: $release->labels,
            media: [],
            trackCount: $release->trackCount(),
            releaseGroup: $release->releaseGroup,
        );
    }

    /** @return array<string, mixed> */
    private static function data(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    private function createUser(string $actor): UserEntity
    {
        $user = User::createByOperator(
            new Email('match-' . $actor . '-' . (random_bytes(8) |> bin2hex(...)) . '@baander.app'),
            'unused-password',
            'Match firewall ' . $actor,
            $actor === 'admin' ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'],
        );
        $this->users->save($user);
        $entity = $this->manager->find(UserEntity::class, $user->getId());
        self::assertInstanceOf(UserEntity::class, $entity);
        $this->entities[] = $entity;

        return $entity;
    }

    private function persist(LibraryEntity|AlbumEntity|ArtistEntity|ArtistAlbumEntity|SongEntity|ArtistSongEntity|ClientEntity|AccessTokenEntity $entity): void
    {
        $this->manager->persist($entity);
        $this->entities[] = $entity;
    }

    private function authenticate(string $actor): void
    {
        $entity = $actor === 'member' ? $this->member : $this->createUser($actor);
        $this->proof = new SignedDpopProof();
        $token = FirstPartyOAuthClient::accessToken(
            $this->kernel->getContainer(),
            $this->manager,
            $entity,
            SignInGrant::Password,
            $this->proof->thumbprint(),
        );
        $this->persist($token);
        $this->manager->flush();
        $this->jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $token);
    }

    /** @param array<string, mixed> $content */
    private function request(string $path, string $method = 'GET', array $content = []): Response
    {
        $this->manager->clear();
        $uri = 'https://baander.app' . $path;
        $request = Request::create($uri, $method, content: $content === [] ? null : json_encode($content, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        if ($this->jwt !== null) {
            $request->headers->set('Authorization', 'DPoP ' . $this->jwt);
            // The proof binds the URI without its query, as the validator compares it.
            $request->headers->set('DPoP', $this->proof->create($method, explode('?', $uri, 2)[0], $this->jwt));
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }
}
