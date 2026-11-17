<?php

declare(strict_types=1);

namespace App\Tests\Functional\Party;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;

/**
 * What a watch-party test starts from: a movie library with a video in it, users who may watch
 * it, and a party its host creates, invites users to and they join, each over the party's routes.
 *
 * @phpstan-require-extends TestCase
 */
trait PartyFixtures
{
    private const string PARTY_SESSIONS_URI = '/api/party/sessions/';

    private function library(): LibraryEntity
    {
        $library = new LibraryEntity(
            name: 'Party test',
            slug: 'party-' . bin2hex(random_bytes(8)),
            path: '/media/party-' . bin2hex(random_bytes(8)),
            type: 'movie',
            filesystemType: 'local',
        );
        $this->entityManager->persist($library);
        $this->entityManager->flush();

        return $library;
    }

    /** A video of a movie in the library. */
    private function video(LibraryEntity $library): VideoEntity
    {
        $video = new VideoEntity(new PublicId(), '/media/party-test.mkv', bin2hex(random_bytes(16)));
        $movie = new MovieEntity(new PublicId(), $library, 'Party test movie');
        $this->entityManager->persist($video);
        $this->entityManager->persist($movie);
        $this->entityManager->persist(new MovieVideoEntity($movie, $video));
        $this->entityManager->flush();

        return $video;
    }

    /** A new user who may watch what the library holds. */
    private function viewer(LibraryEntity $library): User
    {
        $user = $this->createTestUser();
        self::getContainer()->get(LibraryAccessPortInterface::class)->grant($user->getId(), $library->getId());

        return $user;
    }

    /** @return string the public ID of the party the host creates for the video */
    private function createParty(User $host, VideoEntity $video, int $maxMembers = 10): string
    {
        $response = $this->authenticatedRequest('POST', self::PARTY_SESSIONS_URI, $host, [
            'videoId' => $video->getPublicId()->toString(),
            'maxMembers' => $maxMembers,
        ]);

        return $this->assertJsonResponse($response, 201, 'data')['data']['publicId'];
    }

    /** The party's host invites the user, who may then join. */
    private function invite(User $host, User $user, string $party): void
    {
        $response = $this->authenticatedRequest('PUT', $this->invitationUri($user, $party), $host);

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
    }

    private function invitationUri(User|PublicId $user, string $party): string
    {
        $publicId = $user instanceof User ? $user->getPublicId() : $user;

        return self::PARTY_SESSIONS_URI . $party . '/invitations/' . $publicId->toString();
    }

    /**
     * The user joins the party; a scenario about an invited user invites them first.
     *
     * @return array<string, mixed> the member row the join answers with
     */
    private function join(User $user, string $party): array
    {
        return $this->assertJsonResponse($this->authenticatedRequest('POST', self::PARTY_SESSIONS_URI . $party . '/join', $user), 200, 'data')['data'];
    }
}
