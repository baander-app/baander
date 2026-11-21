<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Domain\ValueObject\VideoProbeResult;
use PHPUnit\Framework\TestCase;

final class VideoChapterTest extends TestCase
{
    public function testChapterTimesUseSourceClockAndRejectInvalidRanges(): void
    {
        $probe = VideoProbeResult::fromProbeOutput([
            'format' => ['start_time' => '5', 'duration' => '30'],
            'chapters' => [
                ['start_time' => '10', 'end_time' => '20', 'tags' => ['title' => 'Second']],
                ['start_time' => '4', 'end_time' => '10', 'tags' => ['title' => 'First']],
                ['start_time' => '20', 'end_time' => '19'],
                ['start_time' => 'NaN', 'end_time' => '30'],
                ['start_time' => '40', 'end_time' => '50'],
            ],
        ]);
        self::assertSame(
            [
                ['start' => 0.0, 'end' => 5.0, 'title' => 'First'],
                ['start' => 5.0, 'end' => 15.0, 'title' => 'Second'],
            ],
            $probe->chapters,
        );
        self::assertSame($probe->chapters, VideoProbeResult::fromSerialized($probe->jsonSerialize())->chapters);
        self::assertSame([], VideoProbeResult::fromProbeOutput([])->chapters);
    }
}
