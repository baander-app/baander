<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Local;

use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Filesystem\LibraryMediaFiles;
use App\Library\Infrastructure\Filesystem\MediaFileGuard;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoSidecar;
use App\Metadata\Application\DTO\Video\VideoSidecarKind;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Infrastructure\Local\LocalVideoMetadata;
use App\Metadata\Infrastructure\Local\NfoReader;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Metadata\ProviderSwitches;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class NfoReaderTest extends TestCase
{
    private const int LIMIT = 1_048_576;

    private string $directory;
    private string $root;
    private Uuid $libraryId;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-nfo-' . bin2hex(random_bytes(6));
        $this->root = $this->directory . '/library';
        mkdir($this->root, 0700, true);
        $this->libraryId = Uuid::v7();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testTitleNfoNeverSubstitutesDeclaredEntities(): void
    {
        $nfo = $this->root . '/tvshow.nfo';
        file_put_contents($nfo, <<<'XML'
            <!DOCTYPE tvshow [
                <!ENTITY external SYSTEM "urn:baander:test:nfo-entity">
                <!ENTITY internal "Internal replacement text">
                <!ENTITY provider "tvdb">
            ]>
            <tvshow>
                <title>Show</title><plot>Plot &amp; <![CDATA[details]]>&external;&internal;.</plot>
                <uniqueid type="&provider;">42</uniqueid>
            </tvshow>
            XML);

        $title = $this->identify($nfo);
        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame('Plot & details.', $title->overview);
        self::assertSame([], $title->entry->externalIds);
    }

    public function testAnOversizedOrOutsideRootNfoDoesNotMatch(): void
    {
        $nfo = $this->root . '/tvshow.nfo';
        $xml = '<tvshow><title>Show</title></tvshow>';
        file_put_contents($nfo, str_pad($xml, self::LIMIT));
        self::assertInstanceOf(VideoTitle::class, $this->identify($nfo));
        file_put_contents($nfo, str_pad($xml, self::LIMIT + 1));
        self::assertNull($this->identify($nfo));

        $outside = $this->directory . '/tvshow.nfo';
        file_put_contents($outside, $xml);
        self::assertTrue(symlink($outside, $this->root . '/link.nfo'));
        self::assertNull($this->identify($this->root . '/link.nfo'));
    }

    private function identify(string $path): ?VideoTitle
    {
        $libraries = $this->createStub(LibraryRepositoryInterface::class);
        $libraries->method('findByUuid')->willReturn(Library::create(
            name: 'Show',
            slug: new LibrarySlug('show'),
            path: new LibraryPath($this->root),
            type: LibraryType::TvShow,
            filesystemType: FilesystemType::Local,
        ));
        $files = new LibraryMediaFiles(
            $libraries,
            $this->createStub(LibraryFileIndexRepositoryInterface::class),
            new MediaFileGuard(),
            $this->createStub(EntityManagerInterface::class),
            new MockClock(),
            new NullLogger(),
        );
        $result = new LocalVideoMetadata(new NfoReader($files), ProviderSwitches::allEnabled())->identify(new VideoRequest(
            VideoKind::Series,
            'Show',
            libraryId: $this->libraryId,
            sidecars: [new VideoSidecar(VideoSidecarKind::SeriesNfo, $path)],
            titleId: Uuid::v7(),
        ));
        self::assertNotInstanceOf(VideoProviderProblem::class, $result);

        return $result;
    }
}
