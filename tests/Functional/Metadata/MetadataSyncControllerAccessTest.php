<?php

declare(strict_types=1);

namespace App\Tests\Functional\Metadata;

use App\Auth\Domain\Model\User;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Tests\Functional\TestCase;
use Baander\MediaMetadata\Tests\Support\TaggedMediaFiles;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extract and match read any file the server can open, so only administrators reach them.
 * A library member is refused before the file is read; an administrator gets the file's tags.
 */
final class MetadataSyncControllerAccessTest extends TestCase
{
    private TaggedMediaFiles $files;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new TaggedMediaFiles();
        $this->path = $this->files->mp3();
    }

    protected function tearDown(): void
    {
        $this->files->remove();

        parent::tearDown();
    }

    public function testALibraryMemberWhoIsNotAnAdminIsRefusedExtractAndMatch(): void
    {
        $member = $this->libraryMember();

        self::assertSame(Response::HTTP_FORBIDDEN, $this->extract($member)->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->match($member)->getStatusCode());
    }

    public function testAnAdminExtractsTheFilesTags(): void
    {
        $data = $this->assertJsonResponse($this->createAdminUser() |> $this->extract(...), Response::HTTP_OK, 'data')['data'];

        self::assertSame('mp3', $data['format']);
        self::assertSame('Test Song', $data['metadata']['title']);
        self::assertSame('Test Artist', $data['metadata']['artist']);
    }

    public function testAnAdminMatchesTheFileAgainstCandidates(): void
    {
        $data = $this->assertJsonResponse($this->createAdminUser() |> $this->match(...), Response::HTTP_OK, 'data')['data'];

        self::assertSame(['exact'], array_column($data, 'sourceId'));
    }

    private function extract(User $user): Response
    {
        return $this->authenticatedRequest('POST', '/api/metadata/extract', $user, ['path' => $this->path]);
    }

    private function match(User $user): Response
    {
        return $this->authenticatedRequest('POST', '/api/metadata/match', $user, [
            'path' => $this->path,
            'candidates' => [['title' => 'Test Song', 'artist' => 'Test Artist', 'album' => 'Test Album', 'sourceId' => 'exact']],
        ]);
    }

    /** A user without an admin role who was granted a music library. */
    private function libraryMember(): User
    {
        $library = new LibraryEntity(
            name: 'Metadata access test',
            slug: 'metadata-access-' . (random_bytes(8) |> bin2hex(...)),
            path: $this->files->directory(),
            type: 'music',
            filesystemType: 'local',
        );
        $this->entityManager->persist($library);
        $this->entityManager->flush();

        $member = $this->createTestUser();
        self::getContainer()->get(LibraryAccessPortInterface::class)->grant($member->getId(), $library->getId());

        return $member;
    }
}
