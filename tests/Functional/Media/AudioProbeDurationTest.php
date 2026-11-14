<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Transcode\Application\DTO\AudioProbeResult;
use App\Transcode\Application\Port\AudioProbePortInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Pins the ffprobe fields a song's length is read from, on the FFmpeg build the image ships;
 * ffprobe's reported durations have changed between FFmpeg versions.
 */
final class AudioProbeDurationTest extends KernelTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-probe-duration-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testMp3LengthIsItsEncodedAudioRatherThanItsTlenTag(): void
    {
        $source = $this->encodeThreeSeconds('track.mp3', ['-c:a', 'libmp3lame', '-b:a', '128k', '-metadata', 'TLEN=238']);
        // TLEN counts milliseconds, so the tag claims 0.238 seconds for three seconds of audio.
        self::assertSame('238', $this->formatTag($source, 'TLEN'));

        $probe = $this->probe($source);

        self::assertNotNull($probe->duration);
        self::assertEqualsWithDelta(3.0, $probe->duration, 0.05);
        self::assertNotNull($probe->length());
        self::assertEqualsWithDelta(3.0, $probe->length(), 0.05);
    }

    public function testMatroskaLengthIsTheContainerDurationMinusItsStartTime(): void
    {
        $source = $this->encodeThreeSeconds('track.mka', ['-c:a', 'flac', '-output_ts_offset', '1']);

        $probe = $this->probe($source);

        // Matroska audio states no stream duration, and its container duration is the end timestamp.
        self::assertNull($probe->duration);
        self::assertNotNull($probe->containerStartTime);
        self::assertEqualsWithDelta(1.0, $probe->containerStartTime, 0.001);
        self::assertNotNull($probe->containerDuration);
        self::assertEqualsWithDelta(4.0, $probe->containerDuration, 0.05);
        self::assertNotNull($probe->length());
        self::assertEqualsWithDelta(3.0, $probe->length(), 0.05);
    }

    private function probe(string $source): AudioProbeResult
    {
        return self::getContainer()->get(AudioProbePortInterface::class)->probeAudio($source);
    }

    /** @param list<string> $output */
    private function encodeThreeSeconds(string $name, array $output): string
    {
        $path = $this->directory . '/' . $name;
        (new Process([
            'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3',
            '-ac', '2', '-ar', '44100', ...$output, $path,
        ]))->mustRun();

        return $path;
    }

    private function formatTag(string $path, string $tag): string
    {
        $probe = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format_tags=' . $tag,
            '-of', 'default=noprint_wrappers=1:nokey=1', $path]);

        return trim($probe->mustRun()->getOutput());
    }
}
