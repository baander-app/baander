<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistCollaboratorEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistSongEntity;
use App\Playlist\Interface\Controller\PlaylistController;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\PlaylistOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Every playlist mutation and the collaborator routes through the production firewall, voter
 * and PostgreSQL adapters, crossed with the owner, a collaborator, a collaborator while the
 * playlist is not collaborative, a member of no related library, an administrator who does
 * not own the playlist, and an anonymous request.
 *
 * Each refused row names the deciding rule: a 401 carries the API's code in the body, a 403
 * is the voter refusal the controller throws, recorded by a kernel-exception probe as the
 * class and attribute message before the firewall rewrites it. Every row compares the
 * playlist's stored name, flag, entries and collaborators before and after the request.
 *
 * The seeded catalog spans three libraries: one the owner and the collaborator both read,
 * one only the owner reads and one only the collaborator reads, so the song each viewer
 * sees differs and a song exists that the collaborator can add but the owner cannot see.
 */
final class PlaylistMutationFirewallTest extends TestCase
{
    /** Every playlist mutation route this matrix covers, by its router name. */
    public const array ROUTE_KEYS = [
        'playlist_store',
        'playlist_update',
        'playlist_destroy',
        'playlist_add_song',
        'playlist_remove_song',
        'playlist_reorder',
        'playlist_add_collaborator',
        'playlist_remove_collaborator',
    ];

    private const array ACTORS = ['owner', 'collaborator', 'collaborator-off', 'unrelated', 'admin', 'anonymous'];

    /**
     * The status each authenticated actor gets per route, in ACTORS order without the
     * anonymous request, and the voter attribute a 403 names.
     *
     * @var array<string, array{statuses: array<string, int>, attribute: ?string}>
     */
    private const array MATRIX = [
        'playlist_store' => ['statuses' => ['owner' => 201, 'collaborator' => 201, 'collaborator-off' => 201, 'unrelated' => 201, 'admin' => 201], 'attribute' => null],
        'playlist_update' => ['statuses' => ['owner' => 200, 'collaborator' => 403, 'collaborator-off' => 403, 'unrelated' => 403, 'admin' => 403], 'attribute' => 'EDIT'],
        'playlist_destroy' => ['statuses' => ['owner' => 204, 'collaborator' => 403, 'collaborator-off' => 403, 'unrelated' => 403, 'admin' => 403], 'attribute' => 'DELETE'],
        'playlist_add_song' => ['statuses' => ['owner' => 201, 'collaborator' => 201, 'collaborator-off' => 403, 'unrelated' => 403, 'admin' => 403], 'attribute' => 'EDIT_SONGS'],
        'playlist_remove_song' => ['statuses' => ['owner' => 204, 'collaborator' => 204, 'collaborator-off' => 403, 'unrelated' => 403, 'admin' => 403], 'attribute' => 'EDIT_SONGS'],
        'playlist_reorder' => ['statuses' => ['owner' => 200, 'collaborator' => 200, 'collaborator-off' => 403, 'unrelated' => 403, 'admin' => 403], 'attribute' => 'EDIT_SONGS'],
        'playlist_add_collaborator' => ['statuses' => ['owner' => 204, 'collaborator' => 403, 'collaborator-off' => 403, 'unrelated' => 403, 'admin' => 403], 'attribute' => 'MANAGE_COLLABORATORS'],
        'playlist_remove_collaborator' => ['statuses' => ['owner' => 204, 'collaborator' => 204, 'collaborator-off' => 204, 'unrelated' => 403, 'admin' => 403], 'attribute' => 'MANAGE_COLLABORATORS'],
    ];

    private const string CREATED_NAME = 'Matrix created playlist';
    private const string SONG_NOT_FOUND = 'Song not found.';
    private const string SONG_ID_MALFORMED = 'Invalid song_id format.';
    private const string SONG_PATH_ID_MALFORMED = 'Invalid song ID format.';
    private const string SONG_IDS_MALFORMED = 'One or more song IDs have an invalid format.';
    private const string OWNER_AS_COLLABORATOR = 'The playlist owner cannot be added as a collaborator.';

    private static string $directory;
    private static string $privateKey;
    private PlaylistOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private UserRepositoryInterface $users;
    /** @var array<string, array{id: Uuid, publicId: string, jwt: string, proof: SignedDpopProof}> */
    private array $actors = [];
    private Uuid $playlistId;
    private string $playlistPublicId;
    /** @var array<string, Uuid> song key => id */
    private array $songs = [];
    /** @var list<array{class-string, Uuid}> */
    private array $rows = [];
    /** @var ?array{class-string, string} */
    private ?array $refusal = null;
    /** @var list<string> */
    private array $tokens = [];
    /** @var list<string> */
    private array $proofs = [];
    /** @var list<array{string, string}> */
    private array $bodies = [];

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/baander-playlist-mutation-' . bin2hex(random_bytes(8));
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
        $this->kernel = new PlaylistOAuthKernel(self::$directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->users = $users;
        $dispatcher = $container->get(PlaylistOAuthKernel::DISPATCHER);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        // Above the firewall's exception listener (1), which turns a security refusal into an
        // HTTP exception, and the API's exception subscriber (0), which answers the generic envelope.
        $dispatcher->addListener(KernelEvents::EXCEPTION, function (ExceptionEvent $event): void {
            $throwable = $event->getThrowable();
            $this->refusal = [$throwable::class, $throwable->getMessage()];
        }, 64);
        $this->actors = [];
        $this->songs = [];
        $this->rows = [];
        $this->refusal = null;
        $this->tokens = [];
        $this->proofs = [];
        $this->bodies = [];
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager) && $this->manager->isOpen()) {
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
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        $this->assertNoCredentialInResponsesOrLogs();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, int, ?string}> */
    public static function matrix(): iterable
    {
        foreach (self::ROUTE_KEYS as $route) {
            foreach (self::ACTORS as $actor) {
                if ($actor === 'anonymous') {
                    yield $route . ' anonymous' => [$route, $actor, 401, null];
                    continue;
                }
                $status = self::MATRIX[$route]['statuses'][$actor];
                $attribute = self::MATRIX[$route]['attribute'];
                yield $route . ' ' . $actor => [$route, $actor, $status, $status === 403 && $attribute !== null ? PlaylistController::REFUSAL_MESSAGES[$attribute] : null];
            }
        }
    }

    #[DataProvider('matrix')]
    public function testEveryMutationIsDecidedByTheVoterAndLeavesTheRefusedStateUntouched(string $route, string $actor, int $status, ?string $message): void
    {
        if ($actor === 'collaborator-off') {
            $this->turnCollaborationOff();
        }
        $before = $this->state();
        $mutation = $this->mutation($route);

        $response = $this->request($mutation['method'], $mutation['path'], $actor === 'anonymous' ? null : $actor, $mutation['data']);

        $this->assertDecidedBy($response, $status, $message, $route . ' ' . $actor);
        if ($status >= 400) {
            self::assertSame($before, $this->state(), 'A refused mutation must leave the playlist as it was.');
            self::assertNull($this->createdPlaylist(), 'A refused creation must store no playlist.');

            return;
        }
        $this->assertEffect($route, $actor, $before, $response);
    }

    /** @return iterable<string, array{string}> */
    public static function actors(): iterable
    {
        foreach (self::ACTORS as $actor) {
            yield $actor => [$actor];
        }
    }

    /**
     * The positive control for each identity: the owner, a collaborator (whatever the flag)
     * and an administrator view the playlist with their own visible songs, a member of no
     * related library is refused by the voter, and an anonymous request is refused by the
     * firewall. A mis-minted credential would answer 401 here instead of passing as a refusal.
     */
    #[DataProvider('actors')]
    public function testViewingIsDecidedByTheVoterForEveryActor(string $actor): void
    {
        if ($actor === 'collaborator-off') {
            $this->turnCollaborationOff();
        }

        $response = $this->request('GET', $this->playlistPath(), $actor === 'anonymous' ? null : $actor);

        match ($actor) {
            'anonymous' => $this->assertDecidedBy($response, 401, null, 'anonymous view'),
            'unrelated' => $this->assertDecidedBy($response, 403, PlaylistController::REFUSAL_MESSAGES['VIEW'], 'unrelated view'),
            default => $this->assertView($response, $actor, $this->visibleSongs($actor)),
        };
    }

    public function testACollaboratorAddsASongTheOwnerCannotSeeAndEachViewerCountsTheirOwn(): void
    {
        $response = $this->request('POST', $this->playlistPath('/songs'), 'collaborator', ['songId' => $this->songs['c1']->toString()]);

        self::assertSame(201, $response->getStatusCode(), $this->responseDetails($response));
        self::assertSame(['songId' => $this->songs['c1']->toString(), 'position' => 5], $this->json($response));
        self::assertSame($this->songIds(['s1', 'o1', 's2', 'o2', 's3', 'c1']), $this->state()['songs']);
        $this->assertView($this->request('GET', $this->playlistPath(), 'owner'), 'owner', ['s1', 'o1', 's2', 'o2', 's3']);
        $this->assertView($this->request('GET', $this->playlistPath(), 'collaborator'), 'collaborator', ['s1', 's2', 's3', 'c1']);
        $this->assertView($this->request('GET', $this->playlistPath(), 'admin'), 'admin', ['s1', 'o1', 's2', 'o2', 's3', 'c1']);
    }

    public function testTurningCollaborationOffRefusesTheCollaboratorsEditsAndKeepsTheirView(): void
    {
        $this->turnCollaborationOff();
        $before = $this->state();

        $this->assertDecidedBy($this->request('POST', $this->playlistPath('/songs'), 'collaborator', ['songId' => $this->songs['s4']->toString()]), 403, PlaylistController::REFUSAL_MESSAGES['EDIT_SONGS'], 'add with the flag off');
        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath('/songs/' . $this->songs['s1']->toString()), 'collaborator'), 403, PlaylistController::REFUSAL_MESSAGES['EDIT_SONGS'], 'remove with the flag off');
        $this->assertDecidedBy($this->request('POST', $this->playlistPath('/reorder'), 'collaborator', ['songIds' => $this->songIds(['s3', 's1'])]), 403, PlaylistController::REFUSAL_MESSAGES['EDIT_SONGS'], 'reorder with the flag off');
        self::assertSame($before, $this->state());
        $this->assertView($this->request('GET', $this->playlistPath(), 'collaborator'), 'collaborator', ['s1', 's2', 's3']);

        $turnedOn = $this->request('PATCH', $this->playlistPath(), 'owner', ['isCollaborative' => true]);
        self::assertSame(200, $turnedOn->getStatusCode(), $this->responseDetails($turnedOn));
        self::assertTrue($this->json($turnedOn)['data']['isCollaborative']);
        $added = $this->request('POST', $this->playlistPath('/songs'), 'collaborator', ['songId' => $this->songs['s4']->toString()]);
        self::assertSame(201, $added->getStatusCode(), $this->responseDetails($added));
    }

    public function testASubsetReorderKeepsTheHiddenEntriesAfterTheListedOnes(): void
    {
        $response = $this->request('POST', $this->playlistPath('/reorder'), 'collaborator', ['songIds' => $this->songIds(['s3', 's1', 's2'])]);

        self::assertSame(200, $response->getStatusCode(), $this->responseDetails($response));
        self::assertSame($this->songIds(['s3', 's1', 's2', 'o1', 'o2']), $this->state()['songs']);
        $this->assertView($this->request('GET', $this->playlistPath(), 'collaborator'), 'collaborator', ['s3', 's1', 's2']);
        $this->assertView($this->request('GET', $this->playlistPath(), 'owner'), 'owner', ['s3', 's1', 's2', 'o1', 'o2']);
    }

    public function testCollaboratorManagementIsIdempotentAndNamesUsersByPublicId(): void
    {
        $before = $this->state();
        $unknown = (new PublicId())->toString();

        $this->assertDecidedBy($this->request('PUT', $this->playlistPath('/collaborators/' . $unknown), 'owner'), 404, sprintf('User "%s" not found.', $unknown), 'unknown user');
        self::assertSame($before, $this->state(), 'An unknown user adds no row.');

        $this->assertDecidedBy($this->request('PUT', $this->playlistPath('/collaborators/' . $this->actors['collaborator']['publicId']), 'owner'), 204, null, 'repeated add');
        self::assertSame($before, $this->state(), 'A repeated add keeps the one row.');

        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath('/collaborators/' . $this->actors['unrelated']['publicId']), 'owner'), 204, null, 'removal of a non-collaborator');
        self::assertSame($before, $this->state(), 'Removing a non-collaborator changes nothing.');

        $this->assertDecidedBy($this->request('PUT', $this->playlistPath('/collaborators/' . $this->actors['owner']['publicId']), 'owner'), 422, self::OWNER_AS_COLLABORATOR, 'owner names themself');
        self::assertSame($before, $this->state(), 'The owner is never a collaborator row.');

        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath('/collaborators/' . $this->actors['collaborator']['publicId']), 'collaborator'), 204, null, 'collaborator leaves');
        self::assertSame([], $this->state()['collaborators']);
        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath('/collaborators/' . $this->actors['collaborator']['publicId']), 'collaborator'), 204, null, 'collaborator leaves again');
        self::assertSame([], $this->state()['collaborators']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function collaboratorValuesThatAreNotPublicIds(): iterable
    {
        foreach (['PUT', 'DELETE'] as $method) {
            yield $method . ' email' => [$method, 'collaborator@baander.app'];
            yield $method . ' uuid' => [$method, (new Uuid())->toString()];
            yield $method . ' malformed' => [$method, 'not-a-public-id'];
        }
    }

    /**
     * The route admits only a public id, so the router answers 404 before the controller and
     * the user lookup run: the probe records the router's own not-found exception.
     */
    #[DataProvider('collaboratorValuesThatAreNotPublicIds')]
    public function testTheRouterRefusesACollaboratorValueThatIsNotAPublicId(string $method, string $value): void
    {
        $before = $this->state();

        $response = $this->request($method, $this->playlistPath('/collaborators/' . $value), 'owner');

        self::assertSame(404, $response->getStatusCode(), $this->responseDetails($response));
        self::assertIsArray($this->refusal);
        self::assertSame(NotFoundHttpException::class, $this->refusal[0]);
        self::assertStringStartsWith('No route found for "' . $method . ' ', $this->refusal[1]);
        self::assertSame($before, $this->state());
    }

    public function testOnlyTheOwnerRemovesAnotherUsersCollaboratorRow(): void
    {
        $this->assertDecidedBy($this->request('PUT', $this->playlistPath('/collaborators/' . $this->actors['unrelated']['publicId']), 'owner'), 204, null, 'second collaborator');
        $before = $this->state();
        self::assertSame([$this->actors['collaborator']['id']->toString(), $this->actors['unrelated']['id']->toString()], $before['collaborators']);

        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath('/collaborators/' . $this->actors['unrelated']['publicId']), 'collaborator'), 403, PlaylistController::REFUSAL_MESSAGES['MANAGE_COLLABORATORS'], 'collaborator removes another');
        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath('/collaborators/' . $this->actors['collaborator']['publicId']), 'admin'), 403, PlaylistController::REFUSAL_MESSAGES['MANAGE_COLLABORATORS'], 'administrator removes a collaborator');
        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath('/collaborators/' . $this->actors['collaborator']['publicId']), 'unrelated'), 403, PlaylistController::REFUSAL_MESSAGES['MANAGE_COLLABORATORS'], 'a collaborator removes the other');
        self::assertSame($before, $this->state());
    }

    public function testAnAdministratorWhoDoesNotOwnThePlaylistViewsItAndIsRefusedEveryMutation(): void
    {
        $before = $this->state();

        foreach (self::ROUTE_KEYS as $route) {
            if ($route === 'playlist_store') {
                continue;
            }
            $mutation = $this->mutation($route);
            $message = PlaylistController::REFUSAL_MESSAGES[self::MATRIX[$route]['attribute']];
            $this->assertDecidedBy($this->request($mutation['method'], $mutation['path'], 'admin', $mutation['data']), 403, $message, $route);
        }
        self::assertSame($before, $this->state());
        $this->assertView($this->request('GET', $this->playlistPath(), 'admin'), 'admin', ['s1', 'o1', 's2', 'o2', 's3']);
    }

    public function testARemovedCollaboratorIsRefusedOnTheNextRequest(): void
    {
        $this->assertView($this->request('GET', $this->playlistPath(), 'collaborator'), 'collaborator', ['s1', 's2', 's3']);

        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath('/collaborators/' . $this->actors['collaborator']['publicId']), 'owner'), 204, null, 'owner removes the collaborator');

        $before = $this->state();
        self::assertSame([], $before['collaborators']);
        $this->assertDecidedBy($this->request('GET', $this->playlistPath(), 'collaborator'), 403, PlaylistController::REFUSAL_MESSAGES['VIEW'], 'view after removal');
        $this->assertDecidedBy($this->request('POST', $this->playlistPath('/songs'), 'collaborator', ['songId' => $this->songs['s4']->toString()]), 403, PlaylistController::REFUSAL_MESSAGES['EDIT_SONGS'], 'add after removal');
        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath('/collaborators/' . $this->actors['collaborator']['publicId']), 'collaborator'), 204, null, 'leave after removal');
        self::assertSame($before, $this->state());
        $list = $this->json($this->request('GET', '/api/playlists/', 'collaborator'))['data'];
        self::assertSame([], $list, 'The list no longer carries the playlist.');
    }

    public function testAPlaylistDeletedByItsOwnerAnswers404ToTheCollaborator(): void
    {
        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath(), 'owner'), 204, null, 'owner deletes');
        self::assertFalse($this->state()['exists']);

        $this->assertDecidedBy($this->request('GET', $this->playlistPath(), 'collaborator'), 404, 'Resource not found.', 'view after deletion');
        $this->assertDecidedBy($this->request('POST', $this->playlistPath('/songs'), 'collaborator', ['songId' => $this->songs['s4']->toString()]), 404, 'Resource not found.', 'add after deletion');
        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath('/collaborators/' . $this->actors['collaborator']['publicId']), 'collaborator'), 404, 'Resource not found.', 'leave after deletion');
        self::assertSame([], $this->json($this->request('GET', '/api/playlists/', 'collaborator'))['data']);
    }

    public function testAddingAnUnknownOrInvisibleSongAnswers404AndWritesNothing(): void
    {
        $before = $this->state();

        $this->assertDecidedBy($this->request('POST', $this->playlistPath('/songs'), 'owner', ['songId' => (new Uuid())->toString()]), 404, self::SONG_NOT_FOUND, 'unknown song');
        $this->assertDecidedBy($this->request('POST', $this->playlistPath('/songs'), 'owner', ['songId' => $this->songs['c1']->toString()]), 404, self::SONG_NOT_FOUND, 'song outside the owner\'s libraries');
        $this->assertDecidedBy($this->request('POST', $this->playlistPath('/songs'), 'collaborator', ['songId' => $this->songs['o1']->toString()]), 404, self::SONG_NOT_FOUND, 'song outside the collaborator\'s libraries');
        self::assertSame($before, $this->state());
    }

    public function testAMalformedSongIdAnswers400BeforeAnyLookup(): void
    {
        $before = $this->state();

        $this->assertDecidedBy($this->request('POST', $this->playlistPath('/songs'), 'owner', ['songId' => 'not-a-uuid']), 400, self::SONG_ID_MALFORMED, 'malformed add');
        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath('/songs/not-a-uuid'), 'owner'), 400, self::SONG_PATH_ID_MALFORMED, 'malformed remove');
        $this->assertDecidedBy($this->request('POST', $this->playlistPath('/reorder'), 'owner', ['songIds' => [$this->songs['s1']->toString(), 'not-a-uuid']]), 400, self::SONG_IDS_MALFORMED, 'malformed reorder');
        self::assertSame($before, $this->state());
    }

    public function testDuplicateAddsAndRemovalsOfAnyIdAreIdempotent(): void
    {
        $before = $this->state();

        $duplicate = $this->request('POST', $this->playlistPath('/songs'), 'owner', ['songId' => $this->songs['s1']->toString()]);
        self::assertSame(201, $duplicate->getStatusCode(), $this->responseDetails($duplicate));
        self::assertSame($before, $this->state(), 'A duplicate add keeps the one entry.');

        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath('/songs/' . (new Uuid())->toString()), 'owner'), 204, null, 'removal of an id not in the playlist');
        $this->assertDecidedBy($this->request('DELETE', $this->playlistPath('/songs/' . $this->songs['c1']->toString()), 'owner'), 204, null, 'removal of an id the owner cannot see');
        self::assertSame($before, $this->state());
    }

    /**
     * Seeds the four identities, the three libraries with their songs, and the collaborative
     * playlist holding five songs: three the owner and the collaborator both see, two only
     * the owner sees. One song of the shared library and the collaborator-only song stay out
     * of the playlist for the add rows.
     */
    private function seed(): void
    {
        foreach (['owner', 'collaborator', 'unrelated', 'admin'] as $actor) {
            $this->actors[$actor] = $this->identity($actor);
        }
        $suffix = bin2hex(random_bytes(8));
        $libraries = [
            'shared' => ['owner', 'collaborator'],
            'owner-only' => ['owner'],
            'collaborator-only' => ['collaborator'],
        ];
        $songKeys = ['shared' => ['s1', 's2', 's3', 's4'], 'owner-only' => ['o1', 'o2'], 'collaborator-only' => ['c1']];
        foreach ($libraries as $name => $members) {
            $library = new LibraryEntity('Matrix ' . $name, 'matrix-' . $name . '-' . $suffix, '/tmp/matrix-' . $name . '-' . $suffix, 'music', 'local');
            $album = new AlbumEntity(new PublicId(), $library, $name . ' album', 'album');
            $this->persist($library);
            $this->persist($album);
            foreach ($members as $member) {
                $this->manager->persist(new UserLibraryAccessEntity($this->actors[$member]['id'], $library, new \DateTimeImmutable()));
            }
            foreach ($songKeys[$name] as $track => $key) {
                $song = new SongEntity(new PublicId(), $album, $key . ' song', '/private/' . $name . '/' . $key . '.flac', $track + 1, 'audio/flac');
                $this->persist($song);
                $this->songs[$key] = $song->getId();
            }
        }
        $owner = $this->manager->find(UserEntity::class, $this->actors['owner']['id']);
        $collaborator = $this->manager->find(UserEntity::class, $this->actors['collaborator']['id']);
        self::assertInstanceOf(UserEntity::class, $owner);
        self::assertInstanceOf(UserEntity::class, $collaborator);
        $playlist = new PlaylistEntity(new PublicId(), $owner, 'Matrix playlist');
        $playlist->setCollaborative(true);
        $this->persist($playlist);
        $this->playlistId = $playlist->getId();
        $this->playlistPublicId = $playlist->getPublicId()->toString();
        foreach (['s1', 'o1', 's2', 'o2', 's3'] as $position => $key) {
            $this->manager->persist(new PlaylistSongEntity($playlist, $this->songs[$key], $position));
        }
        $this->manager->persist(new PlaylistCollaboratorEntity($playlist, $collaborator));
        $this->manager->flush();
    }

    /** @return array{id: Uuid, publicId: string, jwt: string, proof: SignedDpopProof} */
    private function identity(string $actor): array
    {
        $user = User::createByOperator(
            new Email('playlist-' . $actor . '-' . bin2hex(random_bytes(8)) . '@baander.app'),
            'test-only-password',
            'Playlist matrix ' . $actor,
            $actor === 'admin' ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'],
        );
        $this->users->save($user);
        $entity = $this->manager->find(UserEntity::class, $user->getId());
        self::assertInstanceOf(UserEntity::class, $entity);
        $this->rows[] = [UserEntity::class, $entity->getId()];
        $proof = new SignedDpopProof();
        $client = new ClientEntity(new PublicId(), 'Playlist matrix client', json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR));
        $token = new AccessTokenEntity((new Uuid())->toString(), $client, $entity, scopes: ['library'], expiresAt: new \DateTimeImmutable('+1 hour'));
        $token->setDpopJkt($proof->thumbprint());
        $this->persist($client);
        $this->persist($token);
        $this->manager->flush();
        $jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $token);
        $this->tokens[] = $jwt;

        return ['id' => $entity->getId(), 'publicId' => $entity->getPublicId()->toString(), 'jwt' => $jwt, 'proof' => $proof];
    }

    private function persist(LibraryEntity|AlbumEntity|SongEntity|PlaylistEntity|ClientEntity|AccessTokenEntity $entity): void
    {
        $this->manager->persist($entity);
        $this->rows[] = [$entity::class, $entity->getId()];
    }

    /** The owner turns collaboration off through the metadata update. */
    private function turnCollaborationOff(): void
    {
        $response = $this->request('PATCH', $this->playlistPath(), 'owner', ['isCollaborative' => false]);
        self::assertSame(200, $response->getStatusCode(), $this->responseDetails($response));
        self::assertFalse($this->json($response)['data']['isCollaborative']);
    }

    /** @return array{method: string, path: string, data: array<string, mixed>} */
    private function mutation(string $route): array
    {
        return match ($route) {
            'playlist_store' => ['method' => 'POST', 'path' => '/api/playlists/', 'data' => ['name' => self::CREATED_NAME]],
            'playlist_update' => ['method' => 'PATCH', 'path' => $this->playlistPath(), 'data' => ['name' => 'Renamed']],
            'playlist_destroy' => ['method' => 'DELETE', 'path' => $this->playlistPath(), 'data' => []],
            'playlist_add_song' => ['method' => 'POST', 'path' => $this->playlistPath('/songs'), 'data' => ['songId' => $this->songs['s4']->toString()]],
            'playlist_remove_song' => ['method' => 'DELETE', 'path' => $this->playlistPath('/songs/' . $this->songs['s1']->toString()), 'data' => []],
            'playlist_reorder' => ['method' => 'POST', 'path' => $this->playlistPath('/reorder'), 'data' => ['songIds' => $this->songIds(['s2', 's1'])]],
            'playlist_add_collaborator' => ['method' => 'PUT', 'path' => $this->playlistPath('/collaborators/' . $this->actors['unrelated']['publicId']), 'data' => []],
            'playlist_remove_collaborator' => ['method' => 'DELETE', 'path' => $this->playlistPath('/collaborators/' . $this->actors['collaborator']['publicId']), 'data' => []],
            default => self::fail('No request is defined for ' . $route),
        };
    }

    /**
     * The stored change a successful row made and the shape of its answer: the resource
     * names the owner and the collaborators by public id, counts the viewer's visible songs
     * and carries no user UUID.
     *
     * @param array{exists: bool, name?: string, collaborative?: bool, songs?: list<string>, collaborators?: list<string>} $before
     */
    private function assertEffect(string $route, string $actor, array $before, Response $response): void
    {
        $expected = $before;
        switch ($route) {
            case 'playlist_store':
                $created = $this->createdPlaylist();
                self::assertInstanceOf(PlaylistEntity::class, $created);
                $this->rows[] = [PlaylistEntity::class, $created->getId()];
                self::assertSame($this->identityOf($actor)['id']->toString(), $created->getUser()->getId()->toString());
                // The creation answers the resource itself, as its documented 201 model.
                $data = $this->json($response);
                self::assertSame($this->identityOf($actor)['publicId'], $data['ownerPublicId']);
                self::assertSame([], $data['collaboratorPublicIds']);
                self::assertSame(0, $data['songCount']);
                self::assertArrayNotHasKey('userId', $data);
                break;
            case 'playlist_update':
                $expected['name'] = 'Renamed';
                $data = $this->json($response)['data'];
                self::assertSame('Renamed', $data['name']);
                self::assertSame($this->actors['owner']['publicId'], $data['ownerPublicId']);
                self::assertSame([$this->actors['collaborator']['publicId']], $data['collaboratorPublicIds']);
                self::assertSame(count($this->visibleSongs($actor)), $data['songCount']);
                self::assertArrayNotHasKey('userId', $data);
                break;
            case 'playlist_destroy':
                $expected = ['exists' => false];
                break;
            case 'playlist_add_song':
                $expected['songs'] = $this->songIds(['s1', 'o1', 's2', 'o2', 's3', 's4']);
                self::assertSame(['songId' => $this->songs['s4']->toString(), 'position' => 5], $this->json($response));
                break;
            case 'playlist_remove_song':
                $expected['songs'] = $this->songIds(['o1', 's2', 'o2', 's3']);
                break;
            case 'playlist_reorder':
                $expected['songs'] = $this->songIds(['s2', 's1', 'o1', 'o2', 's3']);
                break;
            case 'playlist_add_collaborator':
                $expected['collaborators'] = [$this->actors['collaborator']['id']->toString(), $this->actors['unrelated']['id']->toString()];
                break;
            case 'playlist_remove_collaborator':
                $expected['collaborators'] = [];
                break;
            default:
                self::fail('No effect is defined for ' . $route);
        }
        self::assertSame($expected, $this->state(), $route . ' ' . $actor . ' stored a different change.');
    }

    /**
     * The playlist's answer to a viewer: the owner and the collaborators by public id, the
     * viewer's visible songs in position order, their count, and no song path.
     *
     * @param list<string> $songKeys
     */
    private function assertView(Response $response, string $actor, array $songKeys): void
    {
        self::assertSame(200, $response->getStatusCode(), $actor . ' view: ' . $this->responseDetails($response));
        $data = $this->json($response)['data'];
        self::assertSame($this->playlistPublicId, $data['publicId']);
        self::assertSame($this->actors['owner']['publicId'], $data['ownerPublicId']);
        self::assertArrayNotHasKey('userId', $data);
        self::assertSame(count($songKeys), $data['songCount'], $actor . ' counts the songs they can see.');
        self::assertSame($this->songIds($songKeys), array_column($data['songs'], 'uuid'), $actor . ' sees their own songs in order.');
        foreach ($data['songs'] as $song) {
            self::assertArrayNotHasKey('path', $song);
        }
    }

    /**
     * Asserts the status and the deciding component: for a 401 the API's code in the body, for
     * a 403 the voter refusal the probe recorded, for any other status the body's message when
     * one is given.
     */
    private function assertDecidedBy(Response $response, int $status, ?string $discriminator, string $context): void
    {
        self::assertSame($status, $response->getStatusCode(), $context . ': ' . $this->responseDetails($response));
        if ($status === 401) {
            self::assertSame(401, $this->json($response)['error']['code'] ?? null, $context);

            return;
        }
        if ($status === 403) {
            self::assertSame([AccessDeniedException::class, $discriminator], $this->refusal, $context);
            self::assertSame(403, $this->json($response)['error']['code'] ?? null, $context);

            return;
        }
        if ($discriminator !== null) {
            self::assertSame($discriminator, $this->json($response)['error']['message'] ?? null, $context);
        }
    }

    /**
     * The playlist as the database holds it: name, flag, song ids in position order and
     * collaborator user ids oldest first. The identity map is cleared first so a managed row
     * cannot stand in for the stored one.
     *
     * @return array{exists: bool, name?: string, collaborative?: bool, songs?: list<string>, collaborators?: list<string>}
     */
    private function state(): array
    {
        $this->manager->clear();
        $playlist = $this->manager->find(PlaylistEntity::class, $this->playlistId);
        if ($playlist === null) {
            return ['exists' => false];
        }
        $entries = $this->manager->getRepository(PlaylistSongEntity::class)->findBy(['playlist' => $this->playlistId], ['position' => 'ASC']);
        $collaborators = $this->manager->getRepository(PlaylistCollaboratorEntity::class)->findBy(['playlist' => $this->playlistId], ['createdAt' => 'ASC', 'id' => 'ASC']);

        return [
            'exists' => true,
            'name' => $playlist->getName(),
            'collaborative' => $playlist->isCollaborative(),
            'songs' => array_map(static fn(PlaylistSongEntity $entry): string => $entry->getSongId()->toString(), $entries),
            'collaborators' => array_map(static fn(PlaylistCollaboratorEntity $row): string => $row->getUser()->getId()->toString(), $collaborators),
        ];
    }

    private function createdPlaylist(): ?PlaylistEntity
    {
        $this->manager->clear();

        return $this->manager->getRepository(PlaylistEntity::class)->findOneBy(['name' => self::CREATED_NAME]);
    }

    /** @return list<string> */
    private function visibleSongs(string $actor): array
    {
        return match ($actor) {
            'owner', 'admin' => ['s1', 'o1', 's2', 'o2', 's3'],
            'collaborator', 'collaborator-off' => ['s1', 's2', 's3'],
            default => [],
        };
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function songIds(array $keys): array
    {
        return array_map(fn(string $key): string => $this->songs[$key]->toString(), $keys);
    }

    /**
     * The credential of an actor; the collaborator with the flag off is the collaborator.
     *
     * @return array{id: Uuid, publicId: string, jwt: string, proof: SignedDpopProof}
     */
    private function identityOf(string $actor): array
    {
        return $this->actors[$actor === 'collaborator-off' ? 'collaborator' : $actor];
    }

    private function playlistPath(string $suffix = ''): string
    {
        return '/api/playlists/' . $this->playlistPublicId . $suffix;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data, $this->responseDetails($response));

        return $data;
    }

    private function responseDetails(Response $response): string
    {
        if ($response->getStatusCode() < 500) {
            return (string) $response->getContent();
        }
        $details = '';
        $lines = file(self::$directory . '/logs/error.log');
        if ($lines !== false) {
            foreach ($lines as $line) {
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (isset($record['message']) && is_string($record['message'])) {
                    $details = $record['message'];
                }
            }
        }

        return $details;
    }

    /** No response body and no log file of the kernel carries a token or a proof the suite minted. */
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
     * Sends a request through the kernel as the named actor, with that actor's token and a
     * proof from their key, and records the exception a refusal began as.
     *
     * @param array<string, mixed> $data
     */
    private function request(string $method, string $path, ?string $actor = null, array $data = []): Response
    {
        $this->manager->clear();
        $uri = 'https://baander.app' . $path;
        $request = Request::create($uri, $method, content: $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        if ($actor !== null) {
            $identity = $this->identityOf($actor);
            $proof = $identity['proof']->create($method, $uri, $identity['jwt']);
            $this->proofs[] = $proof;
            $request->headers->set('Authorization', 'DPoP ' . $identity['jwt']);
            $request->headers->set('DPoP', $proof);
        }
        $this->refusal = null;
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        $this->bodies[] = [$path, (string) $response->getContent()];

        return $response;
    }
}
