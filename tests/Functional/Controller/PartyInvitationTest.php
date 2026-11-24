<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Party\Domain\Event\MemberLeft;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\Party\PartyFixtures;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * A party's host invites users to it, takes them out again and lists who is invited. A member
 * who is not the host is forbidden all three, and a signed-in user without a member row is
 * answered as for a party that does not exist.
 *
 * The party here has its host, one member who was invited and joined, and an outsider who is
 * neither invited nor a member.
 */
final class PartyInvitationTest extends TestCase
{
    use PartyFixtures;

    private const URI = '/api/party/sessions/';
    private const MISSING = '{"error":{"message":"Resource not found.","code":404}}';

    private LibraryEntity $library;
    private VideoEntity $video;
    private User $host;
    private User $member;
    private User $outsider;
    private string $party;

    /** @var list<string> the IDs of the users the party's room was told have left */
    private array $left = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->library = $this->library();
        $this->video = $this->video($this->library);
        $this->host = $this->viewer($this->library);
        $this->member = $this->viewer($this->library);
        $this->outsider = $this->viewer($this->library);
        $this->party = $this->createParty($this->host, $this->video);
        $this->assertNoContent($this->invite($this->host, $this->member));
        $this->join($this->member, $this->party);

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(MemberLeft::class, function (MemberLeft $event): void {
            $this->left[] = $event->getUserId()->toString();
        });
    }

    public function testACallerWhoIsNotSignedInIsUnauthorized(): void
    {
        foreach ($this->routes($this->party, $this->outsider->getPublicId()) as [$method, $uri]) {
            $this->assertJsonResponse($this->anonymousRequest($method, $uri), 401);
        }
    }

    public function testCreatingAPartyGivesItsCreatorAnInvitation(): void
    {
        $party = $this->createParty($this->outsider, $this->video);

        self::assertSame([$this->outsider->getPublicId()->toString()], $this->invited($this->outsider, $party));
    }

    public function testTheHostInvitesAUserAndARepeatedInviteKeepsTheFirstInvitation(): void
    {
        $this->assertNoContent($this->invite($this->host, $this->outsider));
        $first = $this->storedInvitations($this->outsider);
        self::assertCount(1, $first);

        $this->assertNoContent($this->invite($this->host, $this->outsider));

        self::assertSame($first, $this->storedInvitations($this->outsider), 'The first invitation stands, with the time it was made.');
        self::assertSame($this->publicIds($this->host, $this->member, $this->outsider), $this->invited($this->host));
    }

    public function testAMemberWhoIsNotTheHostIsForbiddenEveryInvitationRoute(): void
    {
        $this->assertJsonResponse($this->invite($this->member, $this->outsider), 403);
        $this->assertJsonResponse($this->revoke($this->member, $this->host), 403);
        $this->assertJsonResponse($this->listInvitations($this->member), 403);

        self::assertSame($this->publicIds($this->host, $this->member), $this->invited($this->host));
        self::assertEqualsCanonicalizing($this->publicIds($this->host, $this->member), $this->members($this->host));
        self::assertSame([], $this->left);
    }

    /** @return iterable<string, array{bool}> whether the host has invited the caller */
    public static function callersWithoutAMemberRow(): iterable
    {
        yield 'a user who is not invited' => [false];
        // An invitation alone shows nothing of the party: only a member row does.
        yield 'an invited user who has not joined' => [true];
    }

    #[DataProvider('callersWithoutAMemberRow')]
    public function testASignedInUserWithoutAMemberRowIsAnsweredAsForAPartyThatDoesNotExist(bool $invited): void
    {
        if ($invited) {
            $this->assertNoContent($this->invite($this->host, $this->outsider));
        }
        $before = $this->invited($this->host);

        $this->assertAnsweredAsForAMissingParty($this->outsider, $this->member->getPublicId());

        self::assertSame($before, $this->invited($this->host));
        self::assertEqualsCanonicalizing($this->publicIds($this->host, $this->member), $this->members($this->host));
        self::assertSame([], $this->left);
    }

    public function testOnlyTheHostLearnsThatNoUserHasAPublicId(): void
    {
        $unknown = new PublicId();

        foreach ([$this->invite($this->host, $unknown), $this->revoke($this->host, $unknown)] as $response) {
            self::assertSame('User not found.', $this->assertJsonResponse($response, 404, 'error')['error']['message']);
        }

        // The same answer as for a user who exists, so it does not say which public IDs are users.
        $this->assertJsonResponse($this->invite($this->member, $unknown), 403);
        $this->assertJsonResponse($this->revoke($this->member, $unknown), 403);
    }

    public function testTheHostCanNeitherInviteNorRevokeThemself(): void
    {
        $this->assertJsonResponse($this->invite($this->host, $this->host), 422);
        $this->assertJsonResponse($this->revoke($this->host, $this->host), 422);

        self::assertSame($this->publicIds($this->host, $this->member), $this->invited($this->host));
        self::assertEqualsCanonicalizing($this->publicIds($this->host, $this->member), $this->members($this->host));
        self::assertSame([], $this->left);
    }

    public function testRevokingAUserWhoWasNeverInvitedChangesNothingAndTellsNobody(): void
    {
        $this->assertNoContent($this->revoke($this->host, $this->outsider));

        self::assertSame($this->publicIds($this->host, $this->member), $this->invited($this->host));
        self::assertEqualsCanonicalizing($this->publicIds($this->host, $this->member), $this->members($this->host));
        self::assertSame([], $this->left);
    }

    public function testRevokingAnInvitedUserWhoHasNotJoinedRemovesTheInvitationAndTellsNobody(): void
    {
        $this->assertNoContent($this->invite($this->host, $this->outsider));
        // The revoke finds the invitation in the database, as a request of its own does.
        $this->entityManager->clear();

        $this->assertNoContent($this->revoke($this->host, $this->outsider));

        self::assertSame($this->publicIds($this->host, $this->member), $this->invited($this->host));
        self::assertSame([], $this->left);
    }

    public function testRevokingAJoinedMemberRemovesTheirInvitationAndMemberRowAndTheRoomHearsThemLeaveOnce(): void
    {
        // The revoke finds the invitation and the member row in the database, as a request of its own does.
        $this->entityManager->clear();

        $this->assertNoContent($this->revoke($this->host, $this->member));

        self::assertSame($this->publicIds($this->host), $this->invited($this->host));
        self::assertSame($this->publicIds($this->host), $this->members($this->host));
        self::assertSame([$this->member->getId()->toString()], $this->left);
        $this->assertAnsweredAsForAMissingParty($this->member, $this->host->getPublicId());

        $this->assertNoContent($this->revoke($this->host, $this->member));
        self::assertSame([$this->member->getId()->toString()], $this->left, 'A user who holds no member row does not leave again.');
    }

    public function testTheListHoldsEachInvitedUsersPublicIdAndInvitationTimeOldestFirstAndNoInternalId(): void
    {
        $this->assertNoContent($this->invite($this->host, $this->outsider));
        // The last invitation becomes the oldest, so the order follows the invitation time.
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE party_invitations SET created_at = TIMESTAMPTZ '2026-01-01 10:00:00+00' WHERE user_id = :user",
            ['user' => $this->outsider->getId()->toString()],
        );
        $this->entityManager->clear();

        $response = $this->listInvitations($this->host);
        $list = $this->assertJsonResponse($response, 200, 'data')['data'];

        self::assertSame($this->publicIds($this->outsider, $this->host, $this->member), array_column($list, 'userId'));
        foreach ($list as $entry) {
            self::assertSame(['userId', 'invitedAt'], array_keys($entry));
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $entry['invitedAt']);
        }
        self::assertEquals(new \DateTimeImmutable('2026-01-01T10:00:00+00:00'), new \DateTimeImmutable($list[0]['invitedAt']));
        self::assertGreaterThan(new \DateTimeImmutable('2026-01-01T10:00:00+00:00'), new \DateTimeImmutable($list[1]['invitedAt']));

        $internalIds = [
            $this->host->getId()->toString(),
            $this->member->getId()->toString(),
            $this->outsider->getId()->toString(),
            ...array_column($this->storedInvitations(), 'id'),
            ...array_column($this->storedInvitations(), 'session_id'),
        ];
        self::assertCount(9, $internalIds);
        foreach ($internalIds as $internalId) {
            self::assertStringNotContainsString((string) $internalId, (string) $response->getContent());
        }
    }

    public function testAfterAHostTransferTheNewHostManagesTheInvitationsAndTheFormerHostIsForbidden(): void
    {
        $this->transferHost($this->host, $this->member);

        $this->assertJsonResponse($this->invite($this->host, $this->outsider), 403);
        $this->assertJsonResponse($this->revoke($this->host, $this->member), 403);
        $this->assertJsonResponse($this->listInvitations($this->host), 403);
        self::assertSame($this->publicIds($this->host, $this->member), $this->invited($this->member));

        $this->assertNoContent($this->invite($this->member, $this->outsider));
        self::assertSame($this->publicIds($this->host, $this->member, $this->outsider), $this->invited($this->member));

        $this->assertNoContent($this->revoke($this->member, $this->outsider));
        self::assertSame($this->publicIds($this->host, $this->member), $this->invited($this->member));
        self::assertSame([], $this->left);
    }

    public function testANewHostRevokesThePartysCreatorWhoIsThenAnsweredAsForAPartyThatDoesNotExist(): void
    {
        $this->transferHost($this->host, $this->member);

        $this->assertNoContent($this->revoke($this->member, $this->host));

        self::assertSame($this->publicIds($this->member), $this->invited($this->member));
        self::assertSame($this->publicIds($this->member), $this->members($this->member));
        self::assertSame([$this->host->getId()->toString()], $this->left);
        $this->assertAnsweredAsForAMissingParty($this->host, $this->member->getPublicId());
    }

    public function testTheInvitationRoutesAnswerTheHostOfAnEndedPartyAsTheyDoForAnActiveOne(): void
    {
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', self::URI . $this->party, $this->host), 200);

        $this->assertNoContent($this->invite($this->host, $this->outsider));
        self::assertSame($this->publicIds($this->host, $this->member, $this->outsider), $this->invited($this->host));

        $this->assertNoContent($this->revoke($this->host, $this->outsider));
        $this->assertNoContent($this->revoke($this->host, $this->member));
        self::assertSame($this->publicIds($this->host), $this->invited($this->host));
        self::assertSame($this->publicIds($this->host), $this->members($this->host));
        self::assertSame([$this->member->getId()->toString()], $this->left);
    }

    /**
     * The caller gets, byte for byte, the answer an unknown party ID gets: from the three
     * invitation routes and from the party's two reads.
     */
    private function assertAnsweredAsForAMissingParty(User $caller, PublicId $namedUser): void
    {
        $unknown = (new PublicId())->toString();
        $hidden = [...$this->routes($this->party, $namedUser), ['GET', self::URI . $this->party], ['GET', self::URI . $this->party . '/members/']];
        $missing = [...$this->routes($unknown, $namedUser), ['GET', self::URI . $unknown], ['GET', self::URI . $unknown . '/members/']];

        foreach ($hidden as $index => [$method, $uri]) {
            $answer = $this->authenticatedRequest($method, $uri, $caller);
            $expected = $this->authenticatedRequest($missing[$index][0], $missing[$index][1], $caller);

            self::assertSame(404, $answer->getStatusCode(), $method . ' ' . $uri);
            self::assertSame(404, $expected->getStatusCode(), $method . ' ' . $missing[$index][1]);
            self::assertSame(self::MISSING, $answer->getContent(), $method . ' ' . $uri);
            self::assertSame($expected->getContent(), $answer->getContent(), $method . ' ' . $uri);
        }
    }

    /** @return list<array{string, string}> the method and URI of each invitation route */
    private function routes(string $party, PublicId $user): array
    {
        $invitations = self::URI . $party . '/invitations/';

        return [
            ['PUT', $invitations . $user->toString()],
            ['DELETE', $invitations . $user->toString()],
            ['GET', $invitations],
        ];
    }

    /**
     * The caller invites the user to the test's party and gets the route's answer back. This takes
     * the place of the shared invite, which asserts that the invite succeeded: the tests here
     * assert on refusals too.
     */
    private function invite(User $caller, User|PublicId $user): Response
    {
        return $this->authenticatedRequest('PUT', $this->invitationUri($user, $this->party), $caller);
    }

    private function revoke(User $caller, User|PublicId $user): Response
    {
        return $this->authenticatedRequest('DELETE', $this->invitationUri($user, $this->party), $caller);
    }

    private function listInvitations(User $caller, ?string $party = null): Response
    {
        return $this->authenticatedRequest('GET', self::URI . ($party ?? $this->party) . '/invitations/', $caller);
    }

    private function assertNoContent(Response $response): void
    {
        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('', $response->getContent());
    }

    /** @return list<string> the invited users' public IDs as the party's host reads them, oldest invitation first */
    private function invited(User $host, ?string $party = null): array
    {
        return array_column($this->assertJsonResponse($this->listInvitations($host, $party), 200, 'data')['data'], 'userId');
    }

    /** @return list<string> the members' user public IDs as a member reads them */
    private function members(User $reader): array
    {
        $response = $this->authenticatedRequest('GET', self::URI . $this->party . '/members/', $reader);

        return array_column($this->assertJsonResponse($response, 200, 'data')['data'], 'userId');
    }

    /** @return list<array<string, mixed>> the stored invitations to the party, of one user or of all: each row's IDs and time */
    private function storedInvitations(?User $user = null): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT invitation.id, invitation.session_id, invitation.user_id, invitation.created_at FROM party_invitations invitation'
            . ' JOIN party_sessions session ON session.id = invitation.session_id'
            . ' WHERE session.public_id = :party ORDER BY invitation.created_at, invitation.id',
            ['party' => $this->party],
        );

        return $user === null
            ? $rows
            : array_values(array_filter($rows, static fn (array $row): bool => $row['user_id'] === $user->getId()->toString()));
    }

    /** @return list<string> */
    private function publicIds(User ...$users): array
    {
        return array_map(static fn (User $user): string => $user->getPublicId()->toString(), array_values($users));
    }

    private function transferHost(User $host, User $target): void
    {
        $response = $this->authenticatedRequest(
            'POST',
            self::URI . $this->party . '/members/transfer-host',
            $host,
            ['newHostUserId' => $target->getPublicId()->toString()],
        );

        $this->assertJsonResponse($response, 200);
    }
}
