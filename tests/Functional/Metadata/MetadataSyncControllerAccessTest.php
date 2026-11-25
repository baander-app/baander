<?php

declare(strict_types=1);

namespace App\Tests\Functional\Metadata;

use App\Auth\Domain\Model\User;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Metadata\Application\Exception\UnreadableMediaFileException;
use App\Tests\Functional\TestCase;
use Baander\MediaMetadata\Tests\Support\TaggedMediaFiles;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extract and match take a server path, so only administrators reach them, and they read only
 * files inside a library. A library member is refused before the file is read; an administrator
 * gets the tags of a library file, and the answer of a missing file for any path no library holds.
 */
final class MetadataSyncControllerAccessTest extends TestCase
{
    private TaggedMediaFiles $files;
    private TaggedMediaFiles $outside;
    private LibraryEntity $library;
    private ?string $link = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new TaggedMediaFiles();
        $this->outside = new TaggedMediaFiles();
        $this->library = $this->libraryAt($this->files->directory());
    }

    protected function tearDown(): void
    {
        if ($this->link !== null) {
            unlink($this->link);
        }
        $this->files->remove();
        $this->outside->remove();

        parent::tearDown();
    }

    public function testALibraryMemberWhoIsNotAnAdminIsRefusedExtractAndMatch(): void
    {
        $member = $this->createTestUser();
        self::getContainer()->get(LibraryAccessPortInterface::class)->grant($member->getId(), $this->library->getId());
        $path = $this->files->mp3();

        self::assertSame(Response::HTTP_FORBIDDEN, $this->extract($member, $path)->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->match($member, $path)->getStatusCode());
    }

    public function testAnAdminExtractsTheTagsOfALibraryFile(): void
    {
        $response = $this->extract($this->createAdminUser(), $this->files->mp3());

        $data = $this->assertJsonResponse($response, Response::HTTP_OK, 'data')['data'];
        self::assertSame('mp3', $data['format']);
        self::assertSame('Test Song', $data['metadata']['title']);
        self::assertSame('Test Artist', $data['metadata']['artist']);
    }

    public function testAnAdminMatchesALibraryFileAgainstCandidates(): void
    {
        $response = $this->match($this->createAdminUser(), $this->files->mp3());

        $data = $this->assertJsonResponse($response, Response::HTTP_OK, 'data')['data'];
        self::assertSame(['exact'], array_column($data, 'sourceId'));
    }

    public function testAFileOutsideEveryLibraryGetsTheAnswerOfAMissingFile(): void
    {
        $admin = $this->createAdminUser();
        $outside = $this->outside->mp3();

        self::assertSame($this->missingAnswer($admin), $this->extract($admin, $outside) |> $this->rejection(...));
        self::assertSame($this->missingAnswer($admin), $this->match($admin, $outside) |> $this->rejection(...));
    }

    public function testALinkInsideALibraryToAFileOutsideIsRefused(): void
    {
        $admin = $this->createAdminUser();
        $this->link = $this->files->directory() . '/linked.mp3';
        self::assertTrue(symlink($this->outside->mp3(), $this->link));

        self::assertSame($this->missingAnswer($admin), $this->extract($admin, $this->link) |> $this->rejection(...));
    }

    public function testAnFtpPathGetsTheAnswerOfAMissingFileAndConnectsNowhere(): void
    {
        $admin = $this->createAdminUser();
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        self::assertIsString($address);
        $ftp = 'ftp://' . $address . '/a.mp3';
        // A regression that reaches the FTP wrapper waits for a greeting; this bounds the wait.
        $timeout = ini_set('default_socket_timeout', '2');
        try {
            self::assertSame($this->missingAnswer($admin), $this->extract($admin, $ftp) |> $this->rejection(...));
            self::assertSame($this->missingAnswer($admin), $this->match($admin, $ftp) |> $this->rejection(...));

            $pending = [$listener];
            $none = null;
            self::assertSame(0, stream_select($pending, $none, $none, 0), 'The server connected to the loopback listener.');
        } finally {
            ini_set('default_socket_timeout', (string) $timeout);
            fclose($listener);
        }
    }

    private function extract(User $user, string $path): Response
    {
        return $this->authenticatedRequest('POST', '/api/metadata/extract', $user, ['path' => $path]);
    }

    private function match(User $user, string $path): Response
    {
        return $this->authenticatedRequest('POST', '/api/metadata/match', $user, [
            'path' => $path,
            'candidates' => [['title' => 'Test Song', 'artist' => 'Test Artist', 'album' => 'Test Album', 'sourceId' => 'exact']],
        ]);
    }

    /**
     * The error the extract route answers for a path inside the library where no file exists.
     *
     * @return array<string, mixed>
     */
    private function missingAnswer(User $admin): array
    {
        $error = $this->extract($admin, $this->files->missing()) |> $this->rejection(...);
        self::assertSame(UnreadableMediaFileException::missing()->getMessage(), $error['message']);

        return $error;
    }

    /** @return array<string, mixed> */
    private function rejection(Response $response): array
    {
        return $this->assertJsonResponse($response, Response::HTTP_UNPROCESSABLE_ENTITY, 'error')['error'];
    }

    private function libraryAt(string $directory): LibraryEntity
    {
        $library = new LibraryEntity(
            name: 'Metadata access test',
            slug: 'metadata-access-' . (random_bytes(8) |> bin2hex(...)),
            path: $directory,
            type: 'music',
            filesystemType: 'local',
        );
        $this->entityManager->persist($library);
        $this->entityManager->flush();

        return $library;
    }
}
