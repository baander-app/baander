<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\Transcode;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Infrastructure\DASH\DashManifestGenerator;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\HLS\QualityLadderRenderer;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use App\Transcode\Infrastructure\Transcode\TranscodeStreamingService;
use PHPUnit\Framework\TestCase;

final class GenerationDeliveryTest extends TestCase
{
    private string $root;
    private TranscodeJob $job;
    private TranscodeStreamingService $service;
    private TranscodeRuntimeSnapshot $runtime;
    private string $directory;
    private bool $retireAfterFirstRead = false;
    private int $runtimeReads = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/baander-generation-'.bin2hex(random_bytes(6));
        $this->job = TranscodeJob::create(Uuid::generate(), QualityTier::p720(), $this->root, Uuid::generate());
        $this->directory = $this->root.'/'.$this->job->getVideoId().'/720p/job-'.$this->job->getId().'/generation-3';
        mkdir($this->directory, 0700, true);
        file_put_contents($this->directory.'/generation.json', json_encode([
            'schemaVersion' => 1, 'generation' => 3, 'videoCodec' => 'h264', 'videoEncoder' => 'libx264',
            'audioCodec' => 'aac', 'audioProfile' => AudioProfile::streamingStereo()->jsonSerialize(),
            'segmentDuration' => 6.0, 'totalSegments' => 2, 'duration' => 10.0, 'startSegment' => 0, 'startTime' => 0.0,
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->directory.'/init.mp4', 'current-init');
        $this->runtime = $this->snapshot();
        $ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $ownership->method('find')->willReturnCallback(function (): TranscodeRuntimeSnapshot {
            if ($this->retireAfterFirstRead && ++$this->runtimeReads > 1) {
                return $this->snapshot(live: false);
            }
            return $this->runtime;
        });
        $jobs = $this->createStub(TranscodeJobRepositoryInterface::class);
        $jobs->method('findByPublicId')->willReturn($this->job);
        $jobs->method('findActiveByVideo')->willReturn([$this->job]);
        $this->service = new TranscodeStreamingService($jobs, new TranscodeFileStorage(new SegmentFileResolver($this->root)), new ManifestGenerator(), new DashManifestGenerator(), new QualityLadderRenderer(), new HmacStreamAuthAdapter('generation-secret'), $ownership);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            !$file->isLink() && $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testManifestsPinGenerationBeforeSigningAndUsePublishedEncoder(): void
    {
        $master = $this->service->getMasterManifest($this->job->getVideoId(), [['jobId' => $this->job->getPublicId()->toString(), 'generation' => 3]]);
        self::assertStringContainsString('avc1.', $master);
        self::assertStringNotContainsString('hvc1.', $master);
        self::assertStringContainsString('generation=3', $master);
        $media = $this->service->getMediaManifest($this->job->getPublicId(), 'streaming_stereo', 3);
        preg_match_all('~(/api/transcode/[^"\s]+)~', $media, $matches);
        self::assertCount(3, $matches[1]);
        $auth = new HmacStreamAuthAdapter('generation-secret');
        foreach ($matches[1] as $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('3', $query['generation']);
            $signature = $query['sig']; $expiry = (int) $query['exp'];
            unset($query['sig'], $query['exp']);
            self::assertTrue($auth->validateUrl(parse_url($url, PHP_URL_PATH).'?'.http_build_query($query), $signature, $expiry));
        }
    }

    public function testExpiredOwnershipAndOldGenerationCannotResolveEvenExistingInit(): void
    {
        self::assertSame($this->directory.'/init.mp4', $this->service->getInitSegmentPath($this->job->getPublicId(), 3));
        self::assertNull($this->service->getInitSegmentPath($this->job->getPublicId(), 2));
        $this->runtime = $this->snapshot(live: false);
        self::assertNull($this->service->getInitSegmentPath($this->job->getPublicId(), 3));
        $this->runtime = $this->snapshot(live: false, outcome: 'complete');
        self::assertSame($this->directory.'/init.mp4', $this->service->getInitSegmentPath($this->job->getPublicId(), 3));
    }

    public function testUntrustedPublishedDirectoryAndMetadataGenerationAreRejected(): void
    {
        $this->runtime = $this->snapshot(directory: $this->root.'/other/generation-3');
        self::assertNull($this->service->getInitSegmentPath($this->job->getPublicId(), 3));
        $this->runtime = $this->snapshot();
        file_put_contents($this->directory.'/generation.json', '{"schemaVersion":1,"generation":2}');
        self::assertNull($this->service->getInitSegmentPath($this->job->getPublicId(), 3));
    }

    public function testSegmentCannotResolveThroughALinkToAnotherGeneration(): void
    {
        $old = dirname($this->directory).'/generation-2';
        mkdir($old);
        file_put_contents($old.'/old.m4s', 'old-generation');
        symlink($old.'/old.m4s', $this->directory.'/v0_a0_720p_0.m4s');
        self::assertNull($this->service->getSegmentPath($this->job->getPublicId(), 0, 3));
    }

    public function testSubtitleUrlsAreSignedForTheirGenerationAndRetirementDiscardsTheManifest(): void
    {
        $subtitles = $this->directory.'/subtitles/s2';
        mkdir($subtitles, 0700, true);
        file_put_contents($subtitles.'/full.vtt', 'WEBVTT');
        $manifest = $this->service->getSubtitleManifest($this->job->getPublicId(), 's2', 3);
        self::assertSame(1, substr_count($manifest, 'generation=3'));
        self::assertStringContainsString('#EXTINF:10.000000,', $manifest);
        self::assertStringContainsString('#EXT-X-TARGETDURATION:10', $manifest);
        self::assertSame(1, substr_count($manifest, '&sig='));
        $this->retireAfterFirstRead = true;
        $manifest = $this->service->getSubtitleManifest($this->job->getPublicId(), 's2', 3);
        self::assertStringNotContainsString('#EXTINF:', $manifest);
    }

    public function testSeekManifestStartsAtThePublishedGenerationRange(): void
    {
        $metadataPath = $this->directory.'/generation.json';
        $metadata = json_decode(file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        $metadata['totalSegments'] = 100;
        $metadata['duration'] = 600.0;
        $metadata['startSegment'] = 27;
        $metadata['startTime'] = 162.0;
        file_put_contents($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));
        $manifest = $this->service->getMediaManifest($this->job->getPublicId(), 'streaming_stereo', 3);
        self::assertStringContainsString('#EXT-X-MEDIA-SEQUENCE:27', $manifest);
        self::assertStringNotContainsString('segment?index=0&', $manifest);
        self::assertStringContainsString('segment?index=27&', $manifest);
        self::assertNull($this->service->getSegmentPath($this->job->getPublicId(), 0, 3));
        $state = $this->service->getPlaybackState($this->job->getVideoId(), [$this->job->getPublicId()->toString()]);
        self::assertSame(162.0, $state['startTime']);
        self::assertSame(600.0, $state['duration']);
    }

    public function testOnlyPublishedTextTracksAreAdvertisedWithDistinctIdentity(): void
    {
        $this->job->updateProbeData(['subtitleStreams' => [
            ['index' => 2, 'language' => 'eng', 'codec' => 'subrip', 'title' => 'Dialogue', 'isDefault' => true],
            ['index' => 3, 'language' => 'eng', 'codec' => 'ass', 'title' => 'Commentary', 'isForced' => true],
            ['index' => 4, 'language' => 'und', 'codec' => 'hdmv_pgs_subtitle', 'title' => 'Bitmap'],
            ['index' => 5, 'language' => 'und', 'codec' => 'subrip', 'title' => 'Not ready'],
        ]]);
        foreach (['s2', 's3', 's4'] as $key) {
            mkdir($this->directory.'/subtitles/'.$key, 0700, true);
            file_put_contents($this->directory.'/subtitles/'.$key.'/full.vtt', 'WEBVTT');
        }
        $master = $this->service->getMasterManifest($this->job->getVideoId(), [['jobId' => $this->job->getPublicId()->toString(), 'generation' => 3]]);
        self::assertSame(2, substr_count($master, 'TYPE=SUBTITLES'));
        self::assertStringContainsString('NAME="Dialogue (s2)",DEFAULT=YES', $master);
        self::assertStringContainsString('NAME="Commentary (s3)",DEFAULT=NO,AUTOSELECT=YES,FORCED=YES', $master);
        self::assertStringContainsString('/subtitles/s2/media.m3u8', $master);
        self::assertStringContainsString('/subtitles/s3/media.m3u8', $master);
    }

    public function testVideoOnlyMetadataOmitsAudioCodecAndUsesWholeRenditionBandwidth(): void
    {
        $path = $this->directory.'/generation.json';
        $metadata = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $metadata['audioCodec'] = null;
        file_put_contents($path, json_encode($metadata, JSON_THROW_ON_ERROR));
        $set = [['jobId' => $this->job->getPublicId()->toString(), 'generation' => 3]];
        $master = $this->service->getMasterManifest($this->job->getVideoId(), $set);
        self::assertStringNotContainsString('mp4a.', $master);
        self::assertStringContainsString('BANDWIDTH='.(int) ceil(QualityTier::p720()->maxBitrate * 1.05), $master);
        $dash = $this->service->getDashManifest($this->job->getVideoId(), $set);
        self::assertStringNotContainsString('mp4a.', $dash);
        self::assertStringContainsString('urn:mpeg:dash:profile:full:2011', $dash);
    }

    private function snapshot(bool $live = true, string $outcome = 'ready', ?string $directory = null): TranscodeRuntimeSnapshot
    {
        return new TranscodeRuntimeSnapshot($this->job->getId(), Uuid::generate(), 'request', new DesiredTranscodeState(AudioProfile::streamingStereo()), 1, 1, 3, $outcome, $directory ?? $this->directory, null, $live);
    }
}
