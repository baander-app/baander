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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ending a party and handing on its host role belong to its host. A member who is not the host
 * is forbidden both, and the host hands the role only to another member of the party.
 */
final class PartyHostActionsTest extends TestCase
{
    private const URI = '/api/party/sessions/';

    private LibraryEntity $library;
    private VideoEntity $video;
    private User $host;
    private User $member;
    private string $party;

    protected function setUp(): void
    {
        parent::setUp();

        $this->library = $this->library();
        $this->video = $this->video($this->library);
        $this->host = $this->viewer();
        $this->member = $this->viewer();
        $this->party = $this->createParty($this->host);
        $this->join($this->member, $this->party);
    }

    public function testAMemberWhoIsNotTheHostIsForbiddenToEndThePartyAndItStaysActive(): void
    {
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', self::URI . $this->party, $this->member), 403);

        $session = $this->session($this->host);
        self::assertTrue($session['isActive']);
        self::assertSame($this->host->getPublicId()->toString(), $session['hostUserId']);
    }

    /** @return iterable<string, array{bool}> whether the named user exists */
    public static function targetsOfAMember(): iterable
    {
        yield 'the host' => [true];
        // The same answer as for a user who exists, so it does not say which public IDs are users.
        yield 'an unknown user' => [false];
    }

    #[DataProvider('targetsOfAMember')]
    public function testAMemberWhoIsNotTheHostIsForbiddenToTransferTheParty(bool $targetExists): void
    {
        $target = $targetExists ? $this->host->getPublicId() : new PublicId();

        $this->assertJsonResponse($this->transfer($this->member, $target), 403);

        self::assertSame($this->host->getPublicId()->toString(), $this->session($this->host)['hostUserId']);
    }

    public function testTheHostCannotHandThePartyToThemself(): void
    {
        $this->assertJsonResponse($this->transfer($this->host, $this->host->getPublicId()), 422);

        self::assertSame($this->host->getPublicId()->toString(), $this->session($this->host)['hostUserId']);
        self::assertEquals(
            [$this->host->getPublicId()->toString() => 'host', $this->member->getPublicId()->toString() => 'member'],
            $this->roles(),
            'The host still holds the host role.',
        );
    }

    public function testTheHostCannotHandThePartyToAUserWhoIsNotAMember(): void
    {
        $outsider = $this->viewer();

        $this->assertJsonResponse($this->transfer($this->host, $outsider->getPublicId()), 422);

        self::assertSame($this->host->getPublicId()->toString(), $this->session($this->host)['hostUserId']);
    }

    public function testTheHostNamingAnUnknownUserIsToldTheUserIsNotFound(): void
    {
        $error = $this->assertJsonResponse($this->transfer($this->host, new PublicId()), 404, 'error')['error'];

        self::assertSame('User not found.', $error['message']);
        self::assertSame($this->host->getPublicId()->toString(), $this->session($this->host)['hostUserId']);
    }

    public function testTheHostHandsThePartyToAMemberWhoAloneMayThenEndIt(): void
    {
        $this->assertJsonResponse($this->transfer($this->host, $this->member->getPublicId()), 200);

        self::assertSame($this->member->getPublicId()->toString(), $this->session($this->host)['hostUserId']);
        self::assertEquals(
            [$this->host->getPublicId()->toString() => 'member', $this->member->getPublicId()->toString() => 'host'],
            $this->roles(),
        );

        $this->assertJsonResponse($this->authenticatedRequest('DELETE', self::URI . $this->party, $this->host), 403);
        self::assertTrue($this->session($this->host)['isActive']);

        $this->assertJsonResponse($this->authenticatedRequest('DELETE', self::URI . $this->party, $this->member), 200);
        self::assertFalse($this->session($this->host)['isActive']);
    }

    private function transfer(User $caller, PublicId $target): Response
    {
        return $this->authenticatedRequest(
            'POST',
            self::URI . $this->party . '/members/transfer-host',
            $caller,
            ['newHostUserId' => $target->toString()],
        );
    }

    /** @return array<string, mixed> the party as one of its members reads it */
    private function session(User $member): array
    {
        return $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $this->party, $member), 200, 'data')['data'];
    }

    /** @return array<string, string> the members' roles by user public ID */
    private function roles(): array
    {
        return array_column($this->session($this->host)['members'], 'role', 'userId');
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

    private function library(): LibraryEntity
    {
        $library = new LibraryEntity(
            name: 'Party host actions test',
            slug: 'party-host-actions-' . bin2hex(random_bytes(8)),
            path: '/media/party-host-actions-' . bin2hex(random_bytes(8)),
            type: 'movie',
            filesystemType: 'local',
        );
        $this->entityManager->persist($library);
        $this->entityManager->flush();

        return $library;
    }

    private function video(LibraryEntity $library): VideoEntity
    {
        $video = new VideoEntity(new PublicId(), '/media/party-host-actions-test.mkv', bin2hex(random_bytes(16)));
        $movie = new MovieEntity(new PublicId(), $library, 'Party host actions test movie');
        $this->entityManager->persist($video);
        $this->entityManager->persist($movie);
        $this->entityManager->persist(new MovieVideoEntity($movie, $video));
        $this->entityManager->flush();

        return $video;
    }
}
