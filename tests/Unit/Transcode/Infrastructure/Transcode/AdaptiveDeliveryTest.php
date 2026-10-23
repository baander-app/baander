<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Transcode;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Application\Port\SleeperInterface;
use App\Tests\Support\PublishedPresentationFixture;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodePublicViewInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Auth\AuthorizedPlayback;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Infrastructure\DASH\DashManifestGenerator;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\HLS\QualityLadderRenderer;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use App\Transcode\Infrastructure\Transcode\TranscodeStreamingService;
use App\Transcode\Interface\Controller\StreamManifestController;
use App\Transcode\Interface\Controller\StreamSigningController;
use App\Transcode\Interface\Security\SignedStreamRequest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AdaptiveDeliveryTest extends TestCase
{
    private string $root;
    private Uuid $video;
    private PublicId $videoPublic;
    private Uuid $actor;
    private Uuid $other;
    private Connection $connection;
    private TranscodeStreamingService $streaming;
    private StreamSigningController $signing;
    private StreamManifestController $manifests;
    /** @var array<string, TranscodeJob> */
    private array $jobs = [];
    /** @var array<string, TranscodeRuntimeSnapshot> */
    private array $runtimes = [];
    /** @var list<string> */
    private array $runtimeReads = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/baander-adaptive-'.bin2hex(random_bytes(5));
        $this->video = Uuid::generate(); $this->videoPublic = new PublicId();
        $this->actor = Uuid::generate(); $this->other = Uuid::generate();
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE videos (id TEXT PRIMARY KEY)',
            'CREATE TABLE movies (id TEXT, library_id TEXT)',
            'CREATE TABLE movie_video (movie_id TEXT, video_id TEXT)',
            'CREATE TABLE user_library_access (user_id TEXT, library_id TEXT)',
            'CREATE TABLE transcode_jobs (id TEXT PRIMARY KEY, public_id TEXT, owner_user_id TEXT, video_id TEXT, height INTEGER, status TEXT)',
            'CREATE TABLE transcode_sessions (id TEXT PRIMARY KEY, public_id TEXT, user_id TEXT, video_id TEXT, job_id TEXT)',
            'CREATE TABLE transcode_runtime_state (job_id TEXT PRIMARY KEY, session_id TEXT)',
        ] as $sql) { $this->connection->executeStatement($sql); }
        $this->connection->insert('videos', ['id' => $this->video->toString()]);
        $this->connection->insert('movies', ['id' => 'movie', 'library_id' => 'shared']);
        $this->connection->insert('movie_video', ['movie_id' => 'movie', 'video_id' => $this->video->toString()]);
        foreach ([$this->actor, $this->other] as $user) {
            $this->connection->insert('user_library_access', ['user_id' => $user->toString(), 'library_id' => 'shared']);
            foreach ([QualityTier::p360(), QualityTier::p720(), QualityTier::p1080()] as $index => $tier) {
                $job = TranscodeJob::create($this->video, $tier, $this->root, $user);
                $session = TranscodeSession::create($user, $job->getId(), $this->video, AudioProfile::streamingStereo());
                $generation = [2, 4, 7][$index];
                $directory = $this->root.'/'.$this->video.'/'.$tier->name.'/job-'.$job->getId().'/generation-'.$generation;
                mkdir($directory, 0700, true);
                file_put_contents($directory.'/generation.json', json_encode(['schemaVersion' => 2, 'generation' => $generation, 'videoCodec' => 'h264', 'videoEncoder' => 'libx264', 'audioCodec' => 'aac', 'audioProfile' => AudioProfile::streamingStereo()->jsonSerialize(), 'duration' => 1000, 'startTime' => 0, 'nativeAvailabilityStartTime' => '2026-10-22T10:00:00Z'], JSON_THROW_ON_ERROR));
                PublishedPresentationFixture::write($directory, $user->equals($this->other) ? 167 : 2, audio: true);
                $runtime = new TranscodeRuntimeSnapshot($job->getId(), $session->getId(), 'admission', new DesiredTranscodeState(AudioProfile::streamingStereo(), $user->equals($this->other) ? 'paused' : 'running', $user->equals($this->other) ? 999 : 0), 1, 1, $generation, 'ready', $directory, null, true);
                $this->jobs[$job->getPublicId()->toString()] = $job;
                $this->runtimes[$job->getId()->toString()] = $runtime;
                $this->connection->insert('transcode_jobs', ['id' => $job->getId()->toString(), 'public_id' => $job->getPublicId()->toString(), 'owner_user_id' => $user->toString(), 'video_id' => $this->video->toString(), 'height' => $tier->height, 'status' => 'in_progress']);
                $this->connection->insert('transcode_sessions', ['id' => $session->getId()->toString(), 'public_id' => $session->getPublicId()->toString(), 'user_id' => $user->toString(), 'video_id' => $this->video->toString(), 'job_id' => $job->getId()->toString()]);
                $this->connection->insert('transcode_runtime_state', ['job_id' => $job->getId()->toString(), 'session_id' => $session->getId()->toString()]);
            }
        }
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturnCallback(fn () => new SecurityUser($this->actor->toString(), 'viewer@baander.app', ''));
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message) => new Envelope($message));
        $playback = new AuthorizedPlayback($security, $this->connection, $bus);
        $jobs = $this->createMock(TranscodeJobRepositoryInterface::class);
        $jobs->expects(self::never())->method('findActiveByVideo');
        $jobs->method('findByPublicId')->willReturnCallback(fn (PublicId $id) => $this->jobs[$id->toString()] ?? null);
        $ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $ownership->method('find')->willReturnCallback(function (Uuid $id) {
            $this->runtimeReads[] = $id->toString();
            return $this->runtimes[$id->toString()] ?? null;
        });
        $auth = new HmacStreamAuthAdapter('adaptive-secret');
        $this->streaming = new TranscodeStreamingService($jobs, new TranscodeFileStorage(new SegmentFileResolver($this->root)), new ManifestGenerator(), new DashManifestGenerator(), new QualityLadderRenderer(), $auth, $ownership, new PublishedPresentationReader());
        $videos = $this->createStub(TranscodePublicViewInterface::class);
        $videos->method('videoId')->willReturn($this->video);
        $this->signing = new StreamSigningController($auth, $playback, $videos, $this->streaming);
        $this->manifests = new StreamManifestController($this->streaming, new SignedStreamRequest($auth), $playback, $videos, $this->createStub(SleeperInterface::class));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($this->root);
    }

    public function testEveryManifestDescendantRetainsTheAuthenticatedParentExpiry(): void
    {
        $primary = array_values($this->jobs)[0];
        $primary->updateProbeData(['subtitleStreams' => [
            ['index' => 2, 'language' => 'eng', 'codec' => 'subrip', 'title' => 'Dialogue', 'isDefault' => true],
        ]]);
        $directory = $this->runtimes[$primary->getId()->toString()]->outputDirectory;
        mkdir($directory.'/subtitles/s2', 0700, true);
        file_put_contents($directory.'/subtitles/s2/full.vtt', 'WEBVTT');
        $expiresAt = time() + 120;
        foreach (['master.m3u8', 'manifest.mpd'] as $name) {
            $signed = $this->sign(true, $name, 120);
            parse_str((string) parse_url($signed['url'], PHP_URL_QUERY), $query);
            unset($query['sig'], $query['exp']);
            $path = parse_url($signed['url'], PHP_URL_PATH).'?'.http_build_query($query);
            $url = $path.'&sig='.hash_hmac('sha256', $path.':'.$expiresAt, 'adaptive-secret').'&exp='.$expiresAt;
            $this->assertSignedExpiry($url, $expiresAt);
            foreach ([1, 2] as $read) {
                $manifest = $name === 'master.m3u8' ? $this->master($url) : $this->dash($url);
                $children = $this->manifestUrls($manifest);
                self::assertCount($name === 'master.m3u8' ? 5 : 12, $children);
                if ($name === 'master.m3u8') {
                    self::assertStringContainsString('/subtitles/s2/media.m3u8?', $manifest);
                    self::assertStringContainsString('/tracks/audio/media.m3u8?', $manifest);
                } else {
                    self::assertStringContainsString('/tracks/audio/init.mp4?', $manifest);
                    self::assertStringContainsString('/tracks/video/segment.m4s?', $manifest);
                }
                foreach ($children as $child) {
                    $this->assertSignedExpiry($child, $expiresAt);
                    if (!str_contains($child, 'media.m3u8')) {
                        continue;
                    }
                    $request = Request::create($child);
                    $path = $request->getPathInfo();
                    preg_match('~/api/transcode/([^/]+)/(tracks|subtitles)/([^/]+)/media\.m3u8$~D', $path, $parts);
                    self::assertCount(4, $parts);
                    $response = $parts[2] === 'subtitles'
                        ? $this->manifests->subtitleManifest($parts[1], $parts[3], $request)
                        : $this->manifests->mediaManifest($parts[1], MediaTrack::from($parts[3]), $request);
                    self::assertSame(200, $response->getStatusCode());
                    ob_start(); $response->sendContent(); $media = (string) ob_get_clean();
                    $resources = $this->manifestUrls($media);
                    self::assertCount($parts[2] === 'subtitles' ? 1 : 3, $resources);
                    self::assertStringContainsString($parts[2] === 'subtitles' ? '/full.vtt?' : '/init.mp4?', $media);
                    if ($parts[2] === 'tracks') {
                        self::assertStringContainsString('/segment.m4s?index=0', $media);
                        self::assertStringContainsString('/segment.m4s?index=1', $media);
                    }
                    foreach ($resources as $resource) {
                        $this->assertSignedExpiry($resource, $expiresAt);
                    }
                }
            }
        }
    }

    /** @return list<string> */
    private function manifestUrls(string $manifest): array
    {
        preg_match_all('~/api/transcode/[^"\s<>]+~', html_entity_decode($manifest, ENT_QUOTES | ENT_XML1), $matches);
        return $matches[0];
    }

    private function assertSignedExpiry(string $url, int $expiresAt): void
    {
        $request = Request::create($url);
        self::assertSame($expiresAt, $request->query->getInt('exp'), $url);
        $signedRequest = new SignedStreamRequest(new HmacStreamAuthAdapter('adaptive-secret'));
        self::assertTrue($signedRequest->isValid($request), $url);
        $request->query->set('exp', (string) ($expiresAt + 1));
        self::assertFalse($signedRequest->isValid($request));
    }

    public function testBothActorsReceiveOnlyTheirOwnCursorAndSignedAdaptiveLadder(): void
    {
        foreach ([false, true] as $refresh) {
            $own = $this->sign($refresh);
            self::assertSame(0, $own['position']);
            self::assertSame('running', $own['status']);
            self::assertNotEmpty($own['sessionId']);
            $manifest = $this->master($own['url']);
            self::assertSame(3, substr_count($manifest, '#EXT-X-STREAM-INF:'));
            foreach ($this->jobs as $job) {
                if ($job->getOwnerUserId()?->equals($this->other)) {
                    self::assertStringNotContainsString($job->getPublicId()->toString(), $manifest);
                    self::assertNotContains($job->getId()->toString(), $this->runtimeReads);
                }
            }
        }
        $this->actor = $this->other;
        $other = $this->sign(true);
        self::assertSame(999, $other['position']);
        self::assertSame('paused', $other['status']);
        self::assertSame(3, substr_count($this->master($other['url']), '#EXT-X-STREAM-INF:'));
    }

    public function testDashCarriesThreeIndividuallyPinnedGenerationsAndStaleSiblingRejectsWholeSet(): void
    {
        $signed = $this->sign(true, 'manifest.mpd');
        $response = $this->manifests->dashManifest($this->videoPublic->toString(), Request::create($signed['url']));
        ob_start(); $response->sendContent(); $manifest = ob_get_clean();
        self::assertSame(4, substr_count($manifest, '<Representation '));
        self::assertSame(1, substr_count($manifest, 'contentType="audio"'));
        foreach ([2, 4, 7] as $generation) { self::assertStringContainsString('generation='.$generation, $manifest); }
        $job = array_values($this->jobs)[1];
        $old = $this->runtimes[$job->getId()->toString()];
        $this->runtimes[$job->getId()->toString()] = new TranscodeRuntimeSnapshot($old->jobId, $old->sessionId, $old->requestId, $old->desired, 2, 1, $old->generation + 1, 'accepted', null, null, true);
        $response = $this->manifests->dashManifest($this->videoPublic->toString(), Request::create($signed['url']));
        self::assertSame(503, $response->getStatusCode());
    }

    public function testSiblingReadinessChangesSetWithoutChangingPrimaryIdentity(): void
    {
        $job = array_values($this->jobs)[1];
        $ready = $this->runtimes[$job->getId()->toString()];
        $this->runtimes[$job->getId()->toString()] = new TranscodeRuntimeSnapshot($ready->jobId, $ready->sessionId, $ready->requestId, $ready->desired, 1, 0, $ready->generation, 'accepted', null, null, true);
        $before = $this->sign(true);
        self::assertSame(2, substr_count($this->master($before['url']), '#EXT-X-STREAM-INF:'));
        $this->runtimes[$job->getId()->toString()] = $ready;
        $after = $this->sign(true);
        self::assertSame($before['jobId'], $after['jobId']);
        self::assertSame($before['generation'], $after['generation']);
        self::assertNotSame($before['renditionSet'], $after['renditionSet']);
        self::assertSame(3, substr_count($this->master($after['url']), '#EXT-X-STREAM-INF:'));
    }

    public function testSignedSetCannotBeAlteredOrDuplicated(): void
    {
        $signed = $this->sign(true);
        parse_str(parse_url($signed['url'], PHP_URL_QUERY), $query);
        $set = $query['set'];
        $auth = new HmacStreamAuthAdapter('adaptive-secret');
        $duplicate = explode(',', $set)[0];
        $request = Request::create($auth->signUrl('/api/transcode/'.$this->videoPublic.'/master.m3u8?'.http_build_query(['set' => $duplicate.','.$duplicate]))['url']);
        try {
            $this->manifests->masterManifest($this->videoPublic->toString(), $request);
            self::fail('Duplicate signed jobs must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\BadRequestHttpException $error) {
            self::assertSame(400, $error->getStatusCode());
        }
        $tampered = str_replace('.4', '.5', $signed['url']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $this->manifests->masterManifest($this->videoPublic->toString(), Request::create($tampered));
    }

    public function testCanonicalAudioIsSharedAcrossVideoRenditions(): void
    {
        $signed = $this->sign(true);
        $master = $this->master($signed['url']);
        self::assertSame(1, substr_count($master, 'TYPE=AUDIO'));
        self::assertSame(3, substr_count($master, 'AUDIO="audio"'));
        self::assertSame(3, substr_count($master, 'CODECS="avc1.64001f,mp4a.40.2"'));
        $primary = array_values($this->jobs)[0];
        self::assertStringContainsString('/'.$primary->getPublicId().'/tracks/audio/media.m3u8', $master);
    }

    public function testUnreadyPrimaryIsNotReplacedAndIncompatibleOriginIsOmitted(): void
    {
        $jobs = array_values($this->jobs);
        $primary = $this->runtimes[$jobs[0]->getId()->toString()];
        $path = $this->runtimes[$jobs[1]->getId()->toString()]->outputDirectory.'/manifest.mpd';
        file_put_contents($path, str_replace('<Period>', '<Period start="PT6S">', file_get_contents($path)));
        $before = $this->sign(true);
        self::assertSame(2, substr_count($this->master($before['url']), '#EXT-X-STREAM-INF:'));
        $this->runtimes[$jobs[0]->getId()->toString()] = new TranscodeRuntimeSnapshot($primary->jobId, $primary->sessionId, $primary->requestId, $primary->desired, 1, 0, $primary->generation, 'accepted', null, null, true);
        $after = $this->sign(true);
        self::assertSame($before['jobId'], $after['jobId']);
        self::assertNull($after['url']);
    }

    public function testMergedDashRemainsDynamicUntilAllSelectedVideosComplete(): void
    {
        $jobs = array_values($this->jobs);
        $sibling = $this->runtimes[$jobs[1]->getId()->toString()];
        PublishedPresentationFixture::write($sibling->outputDirectory, 1, audio: true, complete: false);
        $signed = $this->sign(true, 'manifest.mpd');
        $manifest = $this->dash($signed['url']);
        self::assertStringContainsString('type="dynamic"', $manifest);
        self::assertStringContainsString('availabilityStartTime="2026-10-22T10:00:00Z"', $manifest);
        self::assertSame(4, substr_count($manifest, '<Representation '));
        PublishedPresentationFixture::write($sibling->outputDirectory, 2, audio: true);
        $manifest = $this->dash($signed['url']);
        self::assertStringContainsString('type="static"', $manifest);
        self::assertStringNotContainsString('availabilityStartTime=', $manifest);
    }

    public function testCompletedPrimaryWithoutCapturedClockOmitsGrowingSibling(): void
    {
        $jobs = array_values($this->jobs);
        $primary = $this->runtimes[$jobs[0]->getId()->toString()];
        $path = $primary->outputDirectory.'/generation.json';
        $metadata = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $metadata['nativeAvailabilityStartTime'] = null;
        file_put_contents($path, json_encode($metadata, JSON_THROW_ON_ERROR));
        $sibling = $this->runtimes[$jobs[1]->getId()->toString()];
        PublishedPresentationFixture::write($sibling->outputDirectory, 1, audio: true, complete: false);
        $signed = $this->sign(true);
        self::assertSame(2, substr_count($this->master($signed['url']), '#EXT-X-STREAM-INF:'));
    }

    private function dash(string $url): string
    {
        $response = $this->manifests->dashManifest($this->videoPublic->toString(), Request::create($url));
        self::assertSame(200, $response->getStatusCode());
        ob_start(); $response->sendContent(); return (string) ob_get_clean();
    }

    /** @return array<string, mixed> */
    private function sign(bool $refresh, string $name = 'master.m3u8', int $expiresInSeconds = 86400): array
    {
        $response = $this->signing->sign(Request::create('/api/stream/sign', 'POST', content: json_encode(['path' => '/api/transcode/'.$this->videoPublic.'/'.$name, 'refresh' => $refresh, 'expiresInSeconds' => $expiresInSeconds], JSON_THROW_ON_ERROR)));
        self::assertSame(200, $response->getStatusCode());
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function master(string $url): string
    {
        $response = $this->manifests->masterManifest($this->videoPublic->toString(), Request::create($url));
        self::assertSame(200, $response->getStatusCode());
        ob_start(); $response->sendContent(); return (string) ob_get_clean();
    }
}
