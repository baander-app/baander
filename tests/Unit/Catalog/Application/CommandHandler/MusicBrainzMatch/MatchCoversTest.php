<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\MusicBrainzMatch;

use App\Catalog\Application\CommandHandler\MusicBrainzMatch\MatchCovers;
use App\Filesystem\Mime\MimeDetector;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\Image;
use App\Media\Domain\Model\StoredFile;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCoverImage;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** A match cover's type is read from its bytes, as an uploaded cover's is; the served type is not trusted. */
final class MatchCoversTest extends TestCase
{
    private const string RELEASE_ID = 'f3c3a3b8-5d1e-4c55-9a0e-7a51f4c2d001';

    /** A PNG of one pixel. */
    private const string PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** @var list<string> the path of every file stored */
    private array $stored = [];

    public function testACoverServedAsPngWhoseBytesAreHtmlIsRefusedAndNothingIsStored(): void
    {
        $html = '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>';

        self::assertNull($this->fetch(new MusicBrainzCoverImage($html, 'image/png')));
        self::assertSame([], $this->stored);
    }

    public function testACoverIsStoredWithTheTypeItsBytesShowWhateverTypeWasServed(): void
    {
        $image = $this->fetch(new MusicBrainzCoverImage(base64_decode(self::PIXEL), 'application/octet-stream'));

        self::assertNotNull($image);
        self::assertSame('image/png', $image->getMimeType());
        self::assertSame('png', $image->getExtension());
        self::assertCount(1, $this->stored);
        self::assertStringEndsWith('.png', $this->stored[0]);
    }

    private function fetch(MusicBrainzCoverImage $cover): ?Image
    {
        $lookup = $this->createStub(MusicBrainzLookupInterface::class);
        $lookup->method('fetchFrontCover')->willReturn($cover);
        $storage = $this->createStub(StoragePortInterface::class);
        $storage->method('storeFromBytes')->willReturnCallback(function (string $contents, string $path): StoredFile {
            $this->stored[] = $path;

            return new StoredFile($path, 'image/png', strlen($contents));
        });

        return new MatchCovers($lookup, $storage, new NullLogger(), new MimeDetector())
            ->fetch(Uuid::generate(), self::RELEASE_ID, MusicBrainzSlotWait::Bounded);
    }
}
