<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * Only a party's host and members see it, and only a user its host has invited joins it: the
 * public ID alone admits no one. A signed-in user who is neither a member nor invited is answered
 * as for a party that does not exist.
 *
 * The party here has its host, one member who was invited and joined, and an outsider who is
 * neither invited nor a member.
 */
final class PartySessionAccessTest extends TestCase
{
    private const URI = '/api/party/sessions/';
    private const MISSING = '{"error":{"message":"Resource not found.","code":404}}';

    private LibraryEntity $library;
    private VideoEntity $video;
    private User $host;
    private User $member;
    private User $outsider;
    private string $party;

    protected function setUp(): void
    {
        parent::setUp();

        $this->library = $this->library();
        $this->video = $this->video($this->library);
        $this->host = $this->viewer();
        $this->member = $this->viewer();
        $this->outsider = $this->viewer();
        $this->party = $this->createParty($this->host);
        $this->invite($this->member, $this->party);
        $this->join($this->member, $this->party);
    }

    public function testAnUnauthenticatedCallerIsUnauthorized(): void
    {
        // The reused kernel keeps the token of setUp's last request; clear it so these
        // requests carry no identity.
        self::getContainer()->get('security.token_storage')->setToken(null);

        $this->assertJsonResponse($this->anonymousRequest('GET', self::URI), 401);
        $this->assertJsonResponse($this->anonymousRequest('GET', self::URI . $this->party), 401);
        $this->assertJsonResponse($this->anonymousRequest('GET', $this->membersUri($this->party)), 401);
        $this->assertJsonResponse($this->anonymousRequest('POST', self::URI . $this->party . '/join'), 401);
    }

    public function testAnOutsiderSeesNeitherTheSessionNorItsMembers(): void
    {
        $unknown = (new PublicId())->toString();

        $session = $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $this->party, $this->outsider), 404);
        $members = $this->assertJsonResponse($this->authenticatedRequest('GET', $this->membersUri($this->party), $this->outsider), 404);

        // The answer does not reveal that the party exists.
        self::assertSame($this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $unknown, $this->outsider), 404), $session);
        self::assertSame($this->assertJsonResponse($this->authenticatedRequest('GET', $this->membersUri($unknown), $this->outsider), 404), $members);
    }

    public function testTheHostAndAMemberReadTheSessionAndItsMembers(): void
    {
        $expected = [$this->host->getPublicId()->toString(), $this->member->getPublicId()->toString()];
        sort($expected);

        foreach ([$this->host, $this->member] as $user) {
            $session = $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $this->party, $user), 200, 'data')['data'];
            $members = $this->assertJsonResponse($this->authenticatedRequest('GET', $this->membersUri($this->party), $user), 200, 'data')['data'];

            self::assertSame($this->party, $session['publicId']);
            self::assertSame($expected, $this->sorted(array_column($session['members'], 'userId')));
            self::assertSame($expected, $this->sorted(array_column($members, 'userId')));
        }
    }

    public function testTheSessionListHoldsOnlyTheCallersHostedAndJoinedParties(): void
    {
        $other = $this->viewer();
        $otherParty = $this->createParty($other);
        $memberParty = $this->createParty($this->member);

        self::assertSame($this->sorted([$this->party, $memberParty]), $this->listedParties($this->member));
        self::assertSame([$this->party], $this->listedParties($this->host));
        self::assertSame([$otherParty], $this->listedParties($other));
        self::assertSame([], $this->listedParties($this->outsider));
    }

    /** @return iterable<string, array{string}> the state of the party the uninvited user names */
    public static function partyStates(): iterable
    {
        yield 'an active party' => ['active'];
        // Ended and full show only to a user the party admits.
        yield 'an ended party' => ['ended'];
        yield 'a full party' => ['full'];
    }

    #[DataProvider('partyStates')]
    public function testAnUninvitedUsersJoinIsAnsweredAsForAPartyThatDoesNotExist(string $state): void
    {
        $party = $this->partyIn($state);
        $members = $this->members($party);

        $this->assertJoinIsAnsweredAsForAMissingParty($this->outsider, $party);

        self::assertSame($members, $this->members($party));
        self::assertSame([], $this->listedParties($this->outsider));
    }

    public function testAnInvitedUserJoinsAndThenReadsThePartyAndItsMembersAndFindsItInTheirSessionList(): void
    {
        $this->invite($this->outsider, $this->party);
        // The invitation alone shows nothing of the party: only the member row does.
        $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $this->party, $this->outsider), 404);
        self::assertSame([], $this->listedParties($this->outsider));

        $joined = $this->join($this->outsider, $this->party);

        self::assertSame($this->outsider->getPublicId()->toString(), $joined['userId']);
        self::assertSame($this->party, $joined['sessionId']);
        self::assertSame('member', $joined['role']);
        $session = $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $this->party, $this->outsider), 200, 'data')['data'];
        self::assertSame($this->party, $session['publicId']);
        self::assertSame($this->publicIds($this->host, $this->member, $this->outsider), $this->sorted(array_column($session['members'], 'userId')));
        self::assertSame($this->publicIds($this->host, $this->member, $this->outsider), $this->members($this->party, $this->outsider));
        self::assertSame([$this->party], $this->listedParties($this->outsider));
    }

    public function testAMemberWhoLeftJoinsAgainOnTheSameInvitation(): void
    {
        $this->assertJsonResponse($this->authenticatedRequest('POST', self::URI . $this->party . '/leave', $this->member), 200);
        $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $this->party, $this->member), 404);

        $joined = $this->join($this->member, $this->party);

        self::assertSame('member', $joined['role']);
        self::assertSame($this->publicIds($this->host, $this->member), $this->members($this->party, $this->member));
        self::assertSame([$this->party], $this->listedParties($this->member));
    }

    public function testThePartysCreatorHandsTheHostRoleOverLeavesAndJoinsAgainAsAMember(): void
    {
        $this->assertJsonResponse($this->authenticatedRequest(
            'POST',
            self::URI . $this->party . '/members/transfer-host',
            $this->host,
            ['newHostUserId' => $this->member->getPublicId()->toString()],
        ), 200);
        $this->assertJsonResponse($this->authenticatedRequest('POST', self::URI . $this->party . '/leave', $this->host), 200);
        $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $this->party, $this->host), 404);

        $joined = $this->join($this->host, $this->party);

        self::assertSame('member', $joined['role']);
        $session = $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $this->party, $this->host), 200, 'data')['data'];
        self::assertSame($this->member->getPublicId()->toString(), $session['hostUserId']);
        self::assertEquals(
            [$this->host->getPublicId()->toString() => 'member', $this->member->getPublicId()->toString() => 'host'],
            array_column($session['members'], 'role', 'userId'),
        );
    }

    public function testAFullPartyTakesAMemberBackAndRefusesAnInvitedUser(): void
    {
        $party = $this->partyIn('full');
        $this->invite($this->outsider, $party);
        $rows = $this->memberRows($party);

        // A returning member already holds a place: the host and the member come back to their own rows.
        foreach ([[$this->host, 'host'], [$this->member, 'member']] as [$user, $role]) {
            $joined = $this->join($user, $party);
            self::assertSame($rows[$user->getPublicId()->toString()], $joined['publicId']);
            self::assertSame($role, $joined['role']);
        }

        $refused = $this->assertJsonResponse($this->authenticatedRequest('POST', self::URI . $party . '/join', $this->outsider), 409, 'error')['error'];
        self::assertSame('The party is full.', $refused['message']);
        self::assertSame($this->publicIds($this->host, $this->member), $this->members($party));
        self::assertSame([], $this->listedParties($this->outsider));
    }

    public function testAnEndedPartyRefusesItsHostItsMembersAndAnInvitedUser(): void
    {
        $this->invite($this->outsider, $this->party);
        $party = $this->partyIn('ended');

        foreach ([$this->host, $this->member, $this->outsider] as $user) {
            $refused = $this->assertJsonResponse($this->authenticatedRequest('POST', self::URI . $party . '/join', $user), 409, 'error')['error'];
            self::assertSame('The party has ended.', $refused['message']);
        }

        self::assertSame($this->publicIds($this->host, $this->member), $this->members($party));
    }

    public function testARevokedUsersJoinIsAnsweredAsForAPartyThatDoesNotExist(): void
    {
        // One revoked user had joined, the other was only invited.
        $this->invite($this->outsider, $this->party);
        foreach ([$this->member, $this->outsider] as $user) {
            $revoked = $this->authenticatedRequest('DELETE', $this->invitationUri($user, $this->party), $this->host);
            self::assertSame(204, $revoked->getStatusCode(), (string) $revoked->getContent());
        }

        foreach ([$this->member, $this->outsider] as $user) {
            $this->assertJoinIsAnsweredAsForAMissingParty($user, $this->party);
        }

        self::assertSame($this->publicIds($this->host), $this->members($this->party));
    }

    public function testAMemberWhoLeftNoLongerSeesTheParty(): void
    {
        $this->assertJsonResponse($this->authenticatedRequest('POST', self::URI . $this->party . '/leave', $this->member), 200);

        $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $this->party, $this->member), 404);
        $this->assertJsonResponse($this->authenticatedRequest('GET', $this->membersUri($this->party), $this->member), 404);
        self::assertSame([], $this->listedParties($this->member));
    }

    public function testAnEndedPartyLeavesTheSessionListOfItsHostAndMembers(): void
    {
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', self::URI . $this->party, $this->host), 200);

        // Ending a party keeps its member rows, so only the active filter drops it from the lists.
        self::assertSame([], $this->listedParties($this->host));
        self::assertSame([], $this->listedParties($this->member));
    }

    public function testNoRouteOutsideTheApiServesThePartySession(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        foreach ($router->getRouteCollection() as $name => $route) {
            self::assertStringStartsNotWith('/ws/party', $route->getPath(), $name);
        }

        $content = (string) $this->anonymousRequest('GET', '/ws/party/' . $this->party . '/')->getContent();

        $decoded = json_decode($content, true);
        self::assertFalse(is_array($decoded) && (array_key_exists('session', $decoded) || array_key_exists('data', $decoded)), $content);
        self::assertStringNotContainsString($this->host->getPublicId()->toString(), $content);
        self::assertStringNotContainsString($this->video->getPublicId()->toString(), $content);
    }

    private function viewer(): User
    {
        $user = $this->createTestUser();
        self::getContainer()->get(LibraryAccessPortInterface::class)->grant($user->getId(), $this->library->getId());

        return $user;
    }

    private function createParty(User $host, int $maxMembers = 10): string
    {
        $response = $this->authenticatedRequest('POST', self::URI, $host, [
            'videoId' => $this->video->getPublicId()->toString(),
            'maxMembers' => $maxMembers,
        ]);

        return $this->assertJsonResponse($response, 201, 'data')['data']['publicId'];
    }

    /**
     * The party in the named state, with its host and the member in it: the test's party as it
     * is or ended, or a party of two places, which the two fill.
     */
    private function partyIn(string $state): string
    {
        if ($state === 'full') {
            $party = $this->createParty($this->host, maxMembers: 2);
            $this->invite($this->member, $party);
            $this->join($this->member, $party);

            return $party;
        }
        if ($state === 'ended') {
            $this->assertJsonResponse($this->authenticatedRequest('DELETE', self::URI . $this->party, $this->host), 200);
        }

        return $this->party;
    }

    /**
     * The user's join gets, byte for byte, the answer a join of an unknown party ID gets, in the
     * default language and in Danish.
     */
    private function assertJoinIsAnsweredAsForAMissingParty(User $user, string $party): void
    {
        $unknown = (new PublicId())->toString();
        // A translated refusal reads differently in Danish, so the comparison in Danish is not idle.
        self::assertSame('{"error":{"message":"Forbudt.","code":403}}', $this->requestIn('GET', '/api/admin/users', $user, 'da')->getContent());

        foreach ([null, 'da'] as $language) {
            $hidden = $this->requestIn('POST', self::URI . $party . '/join', $user, $language);
            $missing = $this->requestIn('POST', self::URI . $unknown . '/join', $user, $language);

            self::assertSame(404, $hidden->getStatusCode(), (string) $hidden->getContent());
            self::assertSame(404, $missing->getStatusCode(), (string) $missing->getContent());
            self::assertSame(self::MISSING, $missing->getContent());
            self::assertSame($missing->getContent(), $hidden->getContent());
            self::assertSame($this->headersOf($missing), $this->headersOf($hidden));
        }
    }

    private function requestIn(string $method, string $uri, User $user, ?string $acceptLanguage): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json', BaanderHeader::TestUserId->serverKey() => $user->getId()->toString()];
        if ($acceptLanguage !== null) {
            $server['HTTP_ACCEPT_LANGUAGE'] = $acceptLanguage;
        }
        $this->client->request($method, $uri, server: $server);

        return $this->client->getResponse();
    }

    /** @return array<string, list<string|null>> the response's headers, without the time it was sent */
    private function headersOf(Response $response): array
    {
        $headers = $response->headers->all();
        unset($headers['date']);
        ksort($headers);

        return $headers;
    }

    /** The party's host invites the user. */
    private function invite(User $user, string $party): void
    {
        $response = $this->authenticatedRequest('PUT', $this->invitationUri($user, $party), $this->host);

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
    }

    private function invitationUri(User $user, string $party): string
    {
        return self::URI . $party . '/invitations/' . $user->getPublicId()->toString();
    }

    /**
     * The user joins the party; a scenario about an invited user invites them first.
     *
     * @return array<string, mixed> the member row the join answers with
     */
    private function join(User $user, string $party): array
    {
        return $this->assertJsonResponse($this->authenticatedRequest('POST', self::URI . $party . '/join', $user), 200, 'data')['data'];
    }

    /** @return list<string> the members' user public IDs, sorted, as a member reads them: the host unless another is named */
    private function members(string $party, ?User $reader = null): array
    {
        return $this->sorted(array_keys($this->memberRows($party, $reader)));
    }

    /** @return array<string, string> each member row's public ID by its user's public ID, as a member reads them */
    private function memberRows(string $party, ?User $reader = null): array
    {
        $response = $this->authenticatedRequest('GET', $this->membersUri($party), $reader ?? $this->host);

        return array_column($this->assertJsonResponse($response, 200, 'data')['data'], 'publicId', 'userId');
    }

    /** @return list<string> the users' public IDs, sorted */
    private function publicIds(User ...$users): array
    {
        return $this->sorted(array_map(static fn (User $user): string => $user->getPublicId()->toString(), array_values($users)));
    }

    /** @return list<string> */
    private function listedParties(User $user): array
    {
        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI, $user), 200, 'data')['data'];

        return $this->sorted(array_column($data, 'publicId'));
    }

    private function membersUri(string $party): string
    {
        return self::URI . $party . '/members/';
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function library(): LibraryEntity
    {
        $library = new LibraryEntity(
            name: 'Party access test',
            slug: 'party-access-' . bin2hex(random_bytes(8)),
            path: '/media/party-access-' . bin2hex(random_bytes(8)),
            type: 'movie',
            filesystemType: 'local',
        );
        $this->entityManager->persist($library);
        $this->entityManager->flush();

        return $library;
    }

    private function video(LibraryEntity $library): VideoEntity
    {
        $video = new VideoEntity(new PublicId(), '/media/party-access-test.mkv', bin2hex(random_bytes(16)));
        $movie = new MovieEntity(new PublicId(), $library, 'Party access test movie');
        $this->entityManager->persist($video);
        $this->entityManager->persist($movie);
        $this->entityManager->persist(new MovieVideoEntity($movie, $video));
        $this->entityManager->flush();

        return $video;
    }
}
