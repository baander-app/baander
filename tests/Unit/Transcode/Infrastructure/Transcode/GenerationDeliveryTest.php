<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\Transcode;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\MediaTrack;
use App\Tests\Support\PublishedPresentationFixture;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
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
    private bool $reviseAfterFirstRead = false;
    private ?TranscodeRuntimeSnapshot $advancedRuntime = null;
    private int $runtimeReads = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/baander-generation-'.bin2hex(random_bytes(6));
        $this->job = TranscodeJob::create(Uuid::generate(), QualityTier::p720(), $this->root, Uuid::generate());
        $this->directory = $this->root.'/'.$this->job->getVideoId().'/720p/job-'.$this->job->getId().'/generation-3';
        mkdir($this->directory, 0700, true);
        file_put_contents($this->directory.'/generation.json', json_encode([
            'schemaVersion' => 2, 'generation' => 3, 'videoCodec' => 'h264', 'videoEncoder' => 'libx264',
            'audioCodec' => 'aac', 'audioProfile' => AudioProfile::streamingStereo()->jsonSerialize(),
            'duration' => 10.0, 'startTime' => 0.0, 'nativeAvailabilityStartTime' => '2026-10-22T10:00:00Z',
        ], JSON_THROW_ON_ERROR));
        PublishedPresentationFixture::write($this->directory, 2, audio: true);
        $this->runtime = $this->snapshot();
        $ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $ownership->method('find')->willReturnCallback(function (): TranscodeRuntimeSnapshot {
            ++$this->runtimeReads;
            if ($this->advancedRuntime !== null && $this->runtimeReads > 1) {
                return $this->advancedRuntime;
            }
            if ($this->reviseAfterFirstRead && $this->runtimeReads > 1) {
                $runtime = $this->runtime;
                return new TranscodeRuntimeSnapshot($runtime->jobId, $runtime->sessionId, $runtime->requestId, new DesiredTranscodeState(AudioProfile::opusStereo()), $runtime->desiredRevision + 1, $runtime->observedRevision, $runtime->generation, $runtime->outcome, $runtime->outputDirectory, null, true);
            }
            if ($this->retireAfterFirstRead && $this->runtimeReads > 1) {
                return $this->snapshot(live: false);
            }
            return $this->runtime;
        });
        $jobs = $this->createStub(TranscodeJobRepositoryInterface::class);
        $jobs->method('findByPublicId')->willReturn($this->job);
        $jobs->method('findActiveByVideo')->willReturn([$this->job]);
        $this->service = new TranscodeStreamingService($jobs, new TranscodeFileStorage(new SegmentFileResolver($this->root)), new ManifestGenerator(), new DashManifestGenerator(), new QualityLadderRenderer(), new HmacStreamAuthAdapter('generation-secret'), $ownership, new PublishedPresentationReader());
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
        $master = $this->service->getMasterManifest($this->job->getVideoId(), [['jobId' => $this->job->getPublicId()->toString(), 'generation' => 3]], 2000000000);
        self::assertStringContainsString('CODECS="avc1.64001f,mp4a.40.2"', $master);
        self::assertStringNotContainsString('hvc1.', $master);
        self::assertStringContainsString('generation=3', $master);
        $media = $this->service->getMediaManifest($this->job->getPublicId(), MediaTrack::Video, 3, 2000000000);
        preg_match_all('~(/api/transcode/[^"\s]+)~', $media, $matches);
        self::assertCount(3, $matches[1]);
        $auth = new HmacStreamAuthAdapter('generation-secret');
        foreach ($matches[1] as $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('3', $query['generation']);
            $signature = $query['sig']; $expiry = (int) $query['exp'];
            unset($query['sig'], $query['exp']);
            self::assertTrue($auth->validateUrl(parse_url($url, PHP_URL_PATH).'?'.http_build_query($query), $signature, $expiry));
            self::assertFalse($auth->validateUrl(str_replace('/tracks/video/', '/tracks/audio/', parse_url($url, PHP_URL_PATH)).'?'.http_build_query($query), $signature, $expiry));
            self::assertFalse($auth->validateUrl(parse_url($url, PHP_URL_PATH).'?'.http_build_query(array_replace($query, ['generation' => 2])), $signature, $expiry));
            self::assertFalse($auth->validateUrl(parse_url($url, PHP_URL_PATH).'?'.http_build_query(array_replace($query, ['index' => 99])), $signature, $expiry));
        }
    }

    public function testExpiredOwnershipAndOldGenerationCannotResolveEvenExistingInit(): void
    {
        self::assertSame($this->directory.'/init-0.mp4', $this->service->getInitSegmentPath($this->job->getPublicId(), MediaTrack::Video, 3));
        self::assertNull($this->service->getInitSegmentPath($this->job->getPublicId(), MediaTrack::Video, 2));
        $this->runtime = $this->snapshot(live: false);
        self::assertNull($this->service->getInitSegmentPath($this->job->getPublicId(), MediaTrack::Video, 3));
        $this->runtime = $this->snapshot(live: false, outcome: 'complete');
        self::assertSame($this->directory.'/init-0.mp4', $this->service->getInitSegmentPath($this->job->getPublicId(), MediaTrack::Video, 3));
    }

    public function testAudioProfileChangedDuringNativeIndexReadRejectsDelivery(): void
    {
        $this->reviseAfterFirstRead = true;
        self::assertNull($this->service->getInitSegmentPath($this->job->getPublicId(), MediaTrack::Video, 3));
        self::assertSame(2, $this->runtimeReads);
    }

    public function testPositionTelemetryDuringNativeIndexReadPreservesPublishedAudioResources(): void
    {
        $runtime = $this->runtime;
        $this->advancedRuntime = new TranscodeRuntimeSnapshot(
            $runtime->jobId, $runtime->sessionId, $runtime->requestId,
            new DesiredTranscodeState(AudioProfile::streamingStereo(), position: 1.0),
            $runtime->desiredRevision + 1, $runtime->observedRevision, $runtime->generation,
            $runtime->outcome, $runtime->outputDirectory, null, true,
        );
        self::assertSame($this->directory.'/init-1.mp4', $this->service->getInitSegmentPath($this->job->getPublicId(), MediaTrack::Audio, 3));
        self::assertSame(2, $this->runtimeReads);
        $this->runtimeReads = 0;
        self::assertSame($this->directory.'/track-1-seg-00001.m4s', $this->service->getSegmentPath($this->job->getPublicId(), MediaTrack::Audio, 0, 3));
        self::assertSame(2, $this->runtimeReads);
    }

    public function testTelemetryRevisionCannotBypassGenerationLifecycleOrOwnershipFences(): void
    {
        $runtime = $this->runtime;
        foreach ([[4, 'ready', true], [3, 'cancelled', true], [3, 'ready', false]] as [$generation, $outcome, $live]) {
            $this->runtimeReads = 0;
            $this->advancedRuntime = new TranscodeRuntimeSnapshot(
                $runtime->jobId, $runtime->sessionId, $runtime->requestId,
                new DesiredTranscodeState(AudioProfile::streamingStereo(), position: 1.0),
                $runtime->desiredRevision + 1, $runtime->observedRevision, $generation,
                $outcome, $runtime->outputDirectory, null, $live,
            );
            self::assertNull($this->service->getSegmentPath($this->job->getPublicId(), MediaTrack::Audio, 0, 3));
            self::assertSame(2, $this->runtimeReads);
        }
    }

    public function testUntrustedPublishedDirectoryAndMetadataGenerationAreRejected(): void
    {
        $this->runtime = $this->snapshot(directory: $this->root.'/other/generation-3');
        self::assertNull($this->service->getInitSegmentPath($this->job->getPublicId(), MediaTrack::Video, 3));
        $this->runtime = $this->snapshot();
        file_put_contents($this->directory.'/generation.json', '{"schemaVersion":2,"generation":2}');
        self::assertNull($this->service->getInitSegmentPath($this->job->getPublicId(), MediaTrack::Video, 3));
    }

    public function testSegmentCannotResolveThroughALinkToAnotherGeneration(): void
    {
        $old = dirname($this->directory).'/generation-2';
        mkdir($old);
        file_put_contents($old.'/old.m4s', 'old-generation');
        unlink($this->directory.'/track-0-seg-00001.m4s');
        symlink($old.'/old.m4s', $this->directory.'/track-0-seg-00001.m4s');
        self::assertNull($this->service->getSegmentPath($this->job->getPublicId(), MediaTrack::Video, 0, 3));
    }

    public function testSubtitleUrlsAreSignedForTheirGenerationAndRetirementDiscardsTheManifest(): void
    {
        $subtitles = $this->directory.'/subtitles/s2';
        mkdir($subtitles, 0700, true);
        file_put_contents($subtitles.'/full.vtt', 'WEBVTT');
        $manifest = $this->service->getSubtitleManifest($this->job->getPublicId(), 's2', 3, 2000000000);
        self::assertSame(1, substr_count($manifest, 'generation=3'));
        self::assertStringContainsString('#EXTINF:10.000000,', $manifest);
        self::assertStringContainsString('#EXT-X-TARGETDURATION:10', $manifest);
        self::assertSame(1, substr_count($manifest, '&sig='));
        $this->runtimeReads = 0;
        $this->retireAfterFirstRead = true;
        $manifest = $this->service->getSubtitleManifest($this->job->getPublicId(), 's2', 3, 2000000000);
        self::assertStringNotContainsString('#EXTINF:', $manifest);
    }

    public function testSeekManifestStartsAtThePublishedGenerationRange(): void
    {
        $metadataPath = $this->directory.'/generation.json';
        $metadata = json_decode(file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        $metadata['duration'] = 600.0;
        $metadata['startTime'] = 162.0;
        file_put_contents($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));
        $manifest = $this->service->getMediaManifest($this->job->getPublicId(), MediaTrack::Video, 3, 2000000000);
        self::assertStringContainsString('#EXT-X-MEDIA-SEQUENCE:0', $manifest);
        self::assertStringContainsString('/tracks/video/segment.m4s?index=0&', $manifest);
        self::assertStringNotContainsString('index=27&', $manifest);
        self::assertNotNull($this->service->getSegmentPath($this->job->getPublicId(), MediaTrack::Video, 0, 3));
        $this->runtime = $this->snapshot(position: 162.0);
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
        $master = $this->service->getMasterManifest($this->job->getVideoId(), [['jobId' => $this->job->getPublicId()->toString(), 'generation' => 3]], 2000000000);
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
        PublishedPresentationFixture::write($this->directory, 2, audio: false);
        file_put_contents($path, json_encode($metadata, JSON_THROW_ON_ERROR));
        $set = [['jobId' => $this->job->getPublicId()->toString(), 'generation' => 3]];
        $master = $this->service->getMasterManifest($this->job->getVideoId(), $set, 2000000000);
        self::assertStringNotContainsString('mp4a.', $master);
        self::assertStringContainsString('BANDWIDTH='.(int) ceil(QualityTier::p720()->maxBitrate * 1.05), $master);
        $dash = $this->service->getDashManifest($this->job->getVideoId(), $set, 2000000000);
        self::assertStringNotContainsString('mp4a.', $dash);
        self::assertStringContainsString('urn:mpeg:dash:profile:full:2011', $dash);
    }

    public function testTrackResourcesUseNativeFilesAndSignedGenerationLocalOrdinals(): void
    {
        PublishedPresentationFixture::write($this->directory, 2, audio: true, startNumber: 27);
        foreach ([MediaTrack::Video, MediaTrack::Audio] as $id => $track) {
            self::assertSame($this->directory.'/init-'.$id.'.mp4', $this->service->getInitSegmentPath($this->job->getPublicId(), $track, 3));
            self::assertSame($this->directory.'/track-'.$id.'-seg-00027.m4s', $this->service->getSegmentPath($this->job->getPublicId(), $track, 0, 3));
            self::assertNull($this->service->getSegmentPath($this->job->getPublicId(), $track, 27, 3));
            $manifest = $this->service->getMediaManifest($this->job->getPublicId(), $track, 3, 2000000000);
            self::assertStringContainsString('/tracks/'.$track->value.'/segment.m4s?index=0&generation=3', $manifest);
        }
    }

    public function testGrowingCoverageAndCompletedSourceEndControlReadiness(): void
    {
        PublishedPresentationFixture::write($this->directory, 1, audio: true, complete: false);
        $path = $this->directory.'/generation.json';
        $metadata = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $metadata['duration'] = 12.0;
        file_put_contents($path, json_encode($metadata, JSON_THROW_ON_ERROR));
        $this->runtime = $this->snapshot(position: 12.0);
        $ids = [$this->job->getPublicId()->toString()];
        self::assertFalse($this->service->getPlaybackState($this->job->getVideoId(), $ids)['ready']);
        PublishedPresentationFixture::write($this->directory, 2, audio: true, complete: true);
        $index = $this->directory.'/manifest.mpd';
        $native = new \DOMDocument();
        $native->load($index);
        $native->getElementsByTagName('S')->item(1)->setAttribute('d', '5990');
        $native->save($index);
        self::assertStringContainsString('#EXTINF:5.990000,', $this->service->getMediaManifest($this->job->getPublicId(), MediaTrack::Audio, 3, 2000000000));
        self::assertTrue($this->service->getPlaybackState($this->job->getVideoId(), $ids)['ready']);
    }

    public function testPlaybackStateUsesTheValidatedCanonicalRevisionAndCursor(): void
    {
        $runtime = $this->runtime;
        $this->advancedRuntime = new TranscodeRuntimeSnapshot(
            $runtime->jobId, $runtime->sessionId, $runtime->requestId,
            new DesiredTranscodeState(AudioProfile::streamingStereo(), status: 'paused', position: 3.0),
            2, 2, $runtime->generation, $runtime->outcome, $runtime->outputDirectory, null, true,
        );
        $state = $this->service->getPlaybackState($this->job->getVideoId(), [$this->job->getPublicId()->toString()]);
        self::assertNotNull($state);
        self::assertTrue($state['ready']);
        self::assertSame(2, $state['revision']);
        self::assertSame('paused', $state['status']);
        self::assertSame(3.0, $state['position']);
        self::assertNotEmpty($state['renditionSet']);
    }

    private function snapshot(bool $live = true, string $outcome = 'ready', ?string $directory = null, float $position = 0.0): TranscodeRuntimeSnapshot
    {
        return new TranscodeRuntimeSnapshot($this->job->getId(), Uuid::generate(), 'request', new DesiredTranscodeState(AudioProfile::streamingStereo(), position: $position), 1, 1, 3, $outcome, $directory ?? $this->directory, null, $live);
    }
}
