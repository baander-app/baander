<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * A party's public ID works as its invite: any signed-in user may join with it, and only
 * the party's host and members see the party.
 */
final class PartySessionAccessTest extends TestCase
{
    private const URI = '/api/party/sessions/';

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

    public function testAnyoneSignedInJoinsWithThePublicIdAndThenReadsTheParty(): void
    {
        $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $this->party, $this->outsider), 404);

        $this->join($this->outsider, $this->party);

        $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $this->party, $this->outsider), 200, 'data');
        $members = $this->assertJsonResponse($this->authenticatedRequest('GET', $this->membersUri($this->party), $this->outsider), 200, 'data')['data'];
        self::assertContains($this->outsider->getPublicId()->toString(), array_column($members, 'userId'));
        self::assertSame([$this->party], $this->listedParties($this->outsider));
    }

    public function testAMemberWhoLeftNoLongerSeesTheParty(): void
    {
        $this->assertJsonResponse($this->authenticatedRequest('POST', self::URI . $this->party . '/leave', $this->member), 200);

        $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $this->party, $this->member), 404);
        $this->assertJsonResponse($this->authenticatedRequest('GET', $this->membersUri($this->party), $this->member), 404);
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

    private function createParty(User $host): string
    {
        $response = $this->authenticatedRequest('POST', self::URI, $host, ['videoId' => $this->video->getPublicId()->toString()]);

        return $this->assertJsonResponse($response, 201, 'data')['data']['publicId'];
    }

    private function join(User $user, string $party): void
    {
        $this->assertJsonResponse($this->authenticatedRequest('POST', self::URI . $party . '/join', $user), 200, 'data');
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
