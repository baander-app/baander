<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media\Interface\Controller;

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

/**
 * An original video is typed from its probed container and file extension, never from its bytes,
 * and every original response is a sandboxed download, so a library file padded to sniff as markup
 * cannot run as a page at the application origin.
 */
final class OriginalMediaResponseTest extends TestCase
{
    private const string NAME = 'Amélie (2001).mkv';

    private string $directory;
    private PublicId $videoId;
    private User $member;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/baander-original-response-' . (random_bytes(8) |> bin2hex(...));
        self::assertTrue(mkdir($this->directory, 0700));
        $markup = '<?xml version="1.0"?><x:script xmlns:x="http://www.w3.org/1999/xhtml">alert(document.domain)</x:script><!--';
        file_put_contents($this->directory . '/' . self::NAME, $markup . str_repeat('padding ', 10240) . "\x47\x40\x00\x10");

        $library = new LibraryEntity(
            name: 'Original response test',
            slug: 'original-response-' . (random_bytes(8) |> bin2hex(...)),
            path: $this->directory,
            type: 'movie',
            filesystemType: 'local',
        );
        $video = new VideoEntity(new PublicId(), $this->directory . '/' . self::NAME, random_bytes(16) |> bin2hex(...));
        $video->setProbe(['containerFormat' => 'matroska,webm', 'videoCodec' => 'h264', 'videoStreamIndex' => 0]);
        $movie = new MovieEntity(new PublicId(), $library, 'Original response test movie');
        $this->entityManager->persist($library);
        $this->entityManager->persist($video);
        $this->entityManager->persist($movie);
        $this->entityManager->persist(new MovieVideoEntity($movie, $video));
        $this->entityManager->flush();
        $this->videoId = $video->getPublicId();

        $this->member = $this->createTestUser();
        self::getContainer()->get(LibraryAccessPortInterface::class)->grant($this->member->getId(), $library->getId());
    }

    #[\Override]
    protected function tearDown(): void
    {
        unlink($this->directory . '/' . self::NAME);
        rmdir($this->directory);

        parent::tearDown();
    }

    /** @return iterable<string, array{string, array<string, string>, int}> */
    public static function requests(): iterable
    {
        yield 'GET' => ['GET', [], 200];
        yield 'HEAD' => ['HEAD', [], 200];
        yield 'range' => ['GET', ['HTTP_RANGE' => 'bytes=0-99'], 206];
    }

    /** @param array<string, string> $server */
    #[DataProvider('requests')]
    public function testAnOriginalThatSniffsAsMarkupIsServedAsASandboxedDownloadOfItsContainerType(string $method, array $server, int $status): void
    {
        $this->client->request($method, '/api/stream/video?id=' . $this->videoId->toString(), server: [
            ...$server,
            BaanderHeader::TestUserId->serverKey() => $this->member->getId()->toString(),
        ]);
        $response = $this->client->getResponse();

        self::assertSame($status, $response->getStatusCode());
        self::assertSame('video/x-matroska', $response->headers->get('Content-Type'));
        self::assertSame('attachment', $response->headers->get('Content-Disposition'));
        self::assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testAJsonAnswerCarriesTheDefaultPolicy(): void
    {
        $response = $this->anonymousRequest('GET', '/api/stream/video?id=' . $this->videoId->toString());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
    }
}
