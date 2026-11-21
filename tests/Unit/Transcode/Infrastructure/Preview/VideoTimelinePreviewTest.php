<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Preview;

use App\Catalog\Application\Dto\VideoSourceMetadata;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\PrepareTimelinePreview;
use App\Transcode\Infrastructure\Preview\VideoTimelinePreview;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class VideoTimelinePreviewTest extends TestCase
{
    private string $directory;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-preview-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        file_put_contents($this->directory . '/source', 'source');
    }
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }
    private function source(string $hash = 'hash', ?float $duration = 120.0): VideoSourceMetadata
    {
        return new VideoSourceMetadata(
            new Uuid(),
            '123456789012345678901',
            $this->directory . '/source',
            'source',
            6,
            'video/mp4',
            $hash,
            'mp4',
            'h264',
            2,
            [],
            1920,
            1080,
            24.0,
            $duration,
        );
    }
    public function testRequestsShareOneQueuedPreparationAndNeverExposePartialFiles(): void
    {
        $command = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use (&$command): Envelope {
            $command = $message;
            return new Envelope($message);
        });
        $previews = new VideoTimelinePreview($bus, $this->directory);
        self::assertSame('pending', $previews->describe($this->source())['state']);
        self::assertInstanceOf(PrepareTimelinePreview::class, $command);
        self::assertSame('pending', $previews->describe($this->source())['state']);
        file_put_contents($this->directory . '/' . $command->identity . '.part', 'partial');
        self::assertNull($previews->image($this->source()));
        file_put_contents($this->directory . '/' . $command->identity . '.jpg', 'complete');
        self::assertSame('ready', $previews->describe($this->source())['state']);
        self::assertNotNull($previews->image($this->source()));
        self::assertNull($previews->image($this->source('changed')));
        file_put_contents($this->directory . '/source', 'changed-source-size');
        self::assertNull($previews->image($this->source()));
    }
    public function testExpiredQueueClaimsDoNotPreventPreparation(): void
    {
        for ($index = 0; $index < 256; ++$index) {
            touch($this->directory . '/' . $index . '.pending', time() - 601);
        }
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn(object $message): Envelope => new Envelope($message));
        $previews = new VideoTimelinePreview($bus, $this->directory);

        self::assertSame('pending', $previews->describe($this->source())['state']);
        self::assertCount(1, glob($this->directory . '/*.pending'));
    }

    public function testFullQueuePreservesAnExistingClaimAndRejectsNewWork(): void
    {
        $source = $this->source();
        touch($this->directory . '/' . VideoTimelinePreview::cacheIdentity($source) . '.pending');
        for ($index = 0; $index < 255; ++$index) {
            touch($this->directory . '/' . $index . '.pending');
        }
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $previews = new VideoTimelinePreview($bus, $this->directory);

        self::assertSame('pending', $previews->describe($source)['state']);
        self::assertSame('unavailable', $previews->describe($this->source('new'))['state']);
        self::assertCount(256, glob($this->directory . '/*.pending'));
    }

    public function testUnknownDurationDoesNotQueueAndDispatchFailureKeepsMetadataUsable(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException(new \RuntimeException('queue unavailable'));
        $previews = new VideoTimelinePreview($bus, $this->directory);
        self::assertSame('unavailable', $previews->describe($this->source(duration: null))['state']);
        self::assertSame('failed', $previews->describe($this->source())['state']);
        self::assertNull($previews->image($this->source()));
    }
    public function testActualFramesPublishOneBoundedContactSheet(): void
    {
        if (!is_executable('/usr/bin/ffmpeg')) {
            self::markTestSkipped('FFmpeg is required for actual frame generation.');
        }
        $path = $this->directory . '/movie.mp4';
        $result = \App\Transcode\Infrastructure\FFmpeg\ProcessExecutor::exec([
            '/usr/bin/ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=red:s=320x180:r=2:d=2',
            '-c:v', 'mpeg4', $path,
        ], 10);
        self::assertSame(0, $result['code'], $result['error']);
        $source = new VideoSourceMetadata(
            new Uuid(),
            '123456789012345678901',
            $path,
            'movie.mp4',
            1,
            'video/mp4',
            'hash',
            'mp4',
            'mpeg4',
            0,
            [],
            320,
            180,
            2.0,
            2.0,
        );
        $command = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use (&$command): Envelope {
            $command = $message;
            return new Envelope($message);
        });
        $previews = new VideoTimelinePreview($bus, $this->directory);
        self::assertSame('pending', $previews->describe($source)['state']);
        $previews->prepare(
            $command->path,
            $command->identity,
            $command->duration,
            $command->origin,
            $command->sourceHash,
            $command->videoStreamIndex,
        );
        $image = $previews->image($source);
        self::assertNotNull($image);
        self::assertSame('ready', $previews->describe($source)['state']);
        $dimensions = getimagesize($image);
        self::assertSame(1600, $dimensions[0]);
        self::assertSame(90, $dimensions[1]);
        self::assertLessThan(4_000_000, filesize($image));
        $codec = new \App\Transcode\Infrastructure\Messaging\TimelinePreviewMessagePayloadCodec();
        $fields = $codec->fields('transcode.prepare_timeline_preview');
        $restored = $codec->decode(
            'transcode.prepare_timeline_preview',
            array_combine(
                $fields,
                $codec->encode($command),
            ),
        );
        self::assertEquals($command, $restored);
        VideoTimelinePreview::removeCachedSource($this->directory, VideoTimelinePreview::cacheIdentity($source));
        self::assertNull($previews->image($source));
        $previews->prepare(
            $restored->path,
            $restored->identity,
            $restored->duration,
            $restored->origin,
            $restored->sourceHash,
            $restored->videoStreamIndex,
        );
        self::assertNull($previews->image($source));
        self::assertSame('unavailable', $previews->describe($source)['state']);
    }

    public function testGenerationFailureIsRecordedAndNeverPublished(): void
    {
        $command = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use (&$command): Envelope {
            $command = $message;
            return new Envelope($message);
        });
        $previews = new VideoTimelinePreview($bus, $this->directory);
        $previews->describe($this->source());
        try {
            $previews->prepare(
                $command->path,
                $command->identity,
                $command->duration,
                $command->origin,
                $command->sourceHash,
                $command->videoStreamIndex,
            );
            self::fail('Invalid media must fail generation.');
        } catch (\RuntimeException) {
            self::assertSame('failed', $previews->describe($this->source())['state']);
            self::assertNull($previews->image($this->source()));
        }
    }
}
