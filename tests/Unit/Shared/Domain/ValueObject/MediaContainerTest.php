<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Library\Application\Message\DiscoveredFile;
use App\Shared\Domain\ValueObject\MediaContainer;
use App\Shared\Domain\ValueObject\MediaKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaContainerTest extends TestCase
{
    public function testEachExtensionBelongsToOneContainer(): void
    {
        $extensions = array_merge(...array_map(
            static fn(MediaContainer $container): array => $container->extensions(),
            MediaContainer::cases(),
        ));

        self::assertSame(array_unique($extensions), $extensions);
        foreach (MediaContainer::cases() as $container) {
            foreach ($container->extensions() as $extension) {
                self::assertSame($container, MediaContainer::forExtension($extension));
                self::assertSame($container, MediaContainer::forExtension(strtoupper($extension)));
            }
        }
    }

    public function testTheDemuxersOfEachKindAreListedOnceInTableOrder(): void
    {
        self::assertSame(['mp3', 'flac', 'ogg', 'wav', 'aac', 'mov', 'asf', 'aiff'], MediaContainer::demuxers(MediaKind::Audio));
        self::assertSame(['mov', 'matroska', 'avi', 'asf'], MediaContainer::demuxers(MediaKind::Video));
    }

    #[DataProvider('unsupportedExtensions')]
    public function testFilesOutsideTheTableAreNeitherSongsNorVideos(string $extension): void
    {
        $file = new DiscoveredFile('/library/file.' . $extension, 'file.' . $extension, $extension, 1, 1, 'hash');

        self::assertNull(MediaContainer::forExtension($extension));
        self::assertFalse($file->isAudio());
        self::assertFalse($file->isVideo());
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedExtensions(): iterable
    {
        foreach (['mpd', 'm3u8', 'ts', 'flv', 'mpg', 'xml', 'lrc'] as $extension) {
            yield $extension => [$extension];
        }
    }

    #[DataProvider('identifications')]
    public function testAProbedFormatAndAnExtensionNameOneContainerOfTheKind(
        MediaKind $kind,
        ?string $format,
        string $extension,
        ?MediaContainer $expected,
    ): void {
        self::assertSame($expected, MediaContainer::identify($kind, $format, $extension));
    }

    /** @return iterable<string, array{MediaKind, ?string, string, ?MediaContainer}> */
    public static function identifications(): iterable
    {
        $mp4 = 'mov,mp4,m4a,3gp,3g2,mj2';
        yield 'MP4 film' => [MediaKind::Video, $mp4, 'mp4', MediaContainer::Mpeg4];
        yield 'QuickTime film' => [MediaKind::Video, $mp4, 'MOV', MediaContainer::QuickTime];
        yield 'M4A song' => [MediaKind::Audio, $mp4, 'm4a', MediaContainer::Mpeg4Audio];
        yield 'Matroska named WebM' => [MediaKind::Video, 'matroska,webm', 'webm', MediaContainer::WebM];
        yield 'Matroska named AVI' => [MediaKind::Video, 'matroska,webm', 'avi', MediaContainer::Matroska];
        yield 'Opus in Ogg' => [MediaKind::Audio, 'ogg', 'opus', MediaContainer::Opus];
        yield 'WMV' => [MediaKind::Video, 'asf', 'wmv', MediaContainer::Wmv];
        yield 'WMA' => [MediaKind::Audio, 'asf', 'wma', MediaContainer::Wma];
        yield 'DASH manifest named MKV' => [MediaKind::Video, 'dash', 'mkv', null];
        yield 'MPEG-TS named MKV' => [MediaKind::Video, 'mpegts', 'mkv', null];
        yield 'MP3 probed for a video' => [MediaKind::Video, 'mp3', 'mkv', null];
        yield 'Matroska probed for a song' => [MediaKind::Audio, 'matroska,webm', 'mp3', null];
        yield 'no probed format, known extension' => [MediaKind::Audio, null, 'flac', MediaContainer::Flac];
        yield 'no probed format, extension of the other kind' => [MediaKind::Audio, null, 'mkv', null];
    }
}
