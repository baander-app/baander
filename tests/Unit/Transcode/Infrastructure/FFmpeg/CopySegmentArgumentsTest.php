<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\PresentationSelection;
use App\Transcode\Infrastructure\FFmpeg\CopySegmentArguments;
use PHPUnit\Framework\TestCase;

final class CopySegmentArgumentsTest extends TestCase
{
    public function testCopiesAbsoluteStreamsAndNormalizesToActualSourceBoundary(): void
    {
        $builder = new CopySegmentArguments();
        $selection = new PresentationSelection('copy', 'source-hash', 7, 2, 'mp4');
        $args = $builder->build('/source.mkv', $selection, '/output', 6.17);

        self::assertSame(['0:7', '0:2'], $this->values($args, '-map'));
        self::assertSame(['copy'], $this->values($args, '-c'));
        self::assertSame(['6.170000'], $this->values($args, '-ss'));
        self::assertSame(['-6.170000'], $this->values($args, '-itsoffset'));
        foreach (['-vf', '-af', '-b:v', '-b:a', '-force_key_frames', '-shortest', '-r'] as $option) {
            self::assertNotContains($option, $args);
        }
        self::assertSame('/output/manifest.mpd', $args[array_key_last($args)]);
    }

    public function testVideoWithoutAudioMapsOnlySelectedVideo(): void
    {
        $builder = new CopySegmentArguments();
        $selection = new PresentationSelection('copy', 'source-hash', 3, null, 'mp4');
        $args = $builder->build('/source.mkv', $selection, '/output', 3.25);

        self::assertSame(['0:3'], $this->values($args, '-map'));
        self::assertSame(['id=0,streams=v'], $this->values($args, '-adaptation_sets'));
    }

    /** @param list<string> $arguments
     * @return list<string>
     */
    private function values(array $arguments, string $option): array
    {
        return array_map(static fn(int $index): string => $arguments[$index + 1], array_keys($arguments, $option, true));
    }
}
