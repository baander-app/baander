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
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
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
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\HLS\QualityLadderRenderer;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Swoole\TranscodeSessionSubscriber;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use App\Transcode\Infrastructure\Transcode\TranscodeStreamingService;
use App\Transcode\Interface\Controller\StreamManifestController;
use App\Transcode\Interface\Controller\StreamSigningController;
use App\Transcode\Interface\Security\SignedStreamRequest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private int $groupReads = 0;
    /** @var \Closure(int): void Runs before each group read, with its ordinal; lets a test commit a control between reads. */
    private \Closure $beforeGroupRead;
    private string $requestId;
    /** @var array<string, TranscodeSession> */
    private array $sessions = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/baander-adaptive-'.bin2hex(random_bytes(5));
        $this->beforeGroupRead = static function (): void {};
        $this->requestId = Uuid::generate()->toString();
        $this->video = Uuid::generate(); $this->videoPublic = new PublicId();
        $this->actor = Uuid::generate(); $this->other = Uuid::generate();
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE videos (id TEXT PRIMARY KEY, height INTEGER)',
            'CREATE TABLE movies (id TEXT, library_id TEXT)',
            'CREATE TABLE movie_video (movie_id TEXT, video_id TEXT)',
            'CREATE TABLE user_library_access (user_id TEXT, library_id TEXT)',
            'CREATE TABLE transcode_jobs (id TEXT PRIMARY KEY, public_id TEXT, owner_user_id TEXT, video_id TEXT, height INTEGER, status TEXT, request_id TEXT, presentation_selection TEXT)',
            "CREATE TABLE transcode_sessions (id TEXT PRIMARY KEY, public_id TEXT, user_id TEXT, video_id TEXT, job_id TEXT, updated_at TEXT, state TEXT NOT NULL DEFAULT 'active')",
            'CREATE TABLE transcode_runtime_state (job_id TEXT PRIMARY KEY, session_id TEXT, request_id TEXT)',
        ] as $sql) { $this->connection->executeStatement($sql); }
        $this->connection->insert('videos', ['id' => $this->video->toString(), 'height' => 1080]);
        $this->connection->insert('movies', ['id' => 'movie', 'library_id' => 'shared']);
        $this->connection->insert('movie_video', ['movie_id' => 'movie', 'video_id' => $this->video->toString()]);
        foreach ([$this->actor, $this->other] as $user) {
            $this->connection->insert('user_library_access', ['user_id' => $user->toString(), 'library_id' => 'shared']);
            foreach ([QualityTier::p360(), QualityTier::p720(), QualityTier::p1080()] as $index => $tier) {
                $job = TranscodeJob::create($this->video, $tier, $this->root, $user);
                $session = TranscodeSession::create($user, $job->getId(), $this->video, AudioProfile::streamingStereo());
                $this->sessions[$job->getId()->toString()] = $session;
                $generation = [2, 4, 7][$index];
                $directory = $this->root.'/'.$this->video.'/'.$tier->name.'/job-'.$job->getId().'/generation-'.$generation;
                mkdir($directory, 0700, true);
                file_put_contents($directory.'/generation.json', json_encode(['schemaVersion' => 2, 'generation' => $generation, 'videoCodec' => 'h264', 'videoEncoder' => 'libx264', 'audioCodec' => 'aac', 'audioProfile' => AudioProfile::streamingStereo()->jsonSerialize(), 'duration' => 1000, 'startTime' => 0, 'nativeAvailabilityStartTime' => '2026-10-22T10:00:00Z'], JSON_THROW_ON_ERROR));
                PublishedPresentationFixture::write($directory, $user->equals($this->other) ? 167 : 2, audio: true);
                $runtime = new TranscodeRuntimeSnapshot($job->getId(), $session->getId(), $this->requestId, new DesiredTranscodeState(AudioProfile::streamingStereo(), $user->equals($this->other) ? 'paused' : 'running', $user->equals($this->other) ? 999 : 0), 1, 1, $generation, 'ready', $directory, null, true);
                $this->jobs[$job->getPublicId()->toString()] = $job;
                $this->runtimes[$job->getId()->toString()] = $runtime;
                $this->connection->insert('transcode_jobs', ['id' => $job->getId()->toString(), 'public_id' => $job->getPublicId()->toString(), 'owner_user_id' => $user->toString(), 'video_id' => $this->video->toString(), 'height' => $tier->height, 'status' => 'in_progress', 'request_id' => $this->requestId]);
                $this->connection->insert('transcode_sessions', ['id' => $session->getId()->toString(), 'public_id' => $session->getPublicId()->toString(), 'user_id' => $user->toString(), 'video_id' => $this->video->toString(), 'job_id' => $job->getId()->toString()]);
                $this->connection->insert('transcode_runtime_state', ['job_id' => $job->getId()->toString(), 'session_id' => $session->getId()->toString(), 'request_id' => $this->requestId]);
            }
        }
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturnCallback(fn () => new SecurityUser($this->actor->toString(), 'viewer@baander.app', ''));
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (CreateTranscodeSessionCommand $message): Envelope {
            $job = array_find($this->jobs, static fn (TranscodeJob $job): bool =>
                $job->getOwnerUserId()?->equals($message->getUserId())
                && $job->getQualityTierName() === $message->getQualityTier()->name);
            if ($job === null) {
                throw new \LogicException('The requested fixture rendition does not exist.');
            }
            return new Envelope($message, [new HandledStamp($this->sessions[$job->getId()->toString()], 'fixture')]);
        });
        $playback = new AuthorizedPlayback($security, $this->connection, $bus, new \Symfony\Component\Clock\MockClock());
        $jobs = $this->createMock(TranscodeJobRepositoryInterface::class);
        $jobs->expects(self::never())->method('findActiveByVideo');
        $jobs->method('findByPublicId')->willReturnCallback(fn (PublicId $id) => $this->jobs[$id->toString()] ?? null);
        $ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $ownership->method('find')->willReturnCallback(function (Uuid $id) {
            $this->runtimeReads[] = $id->toString();
            return $this->runtimes[$id->toString()] ?? null;
        });
        $ownership->method('findGroup')->willReturnCallback(function (array $ids): array {
            ++$this->groupReads;
            ($this->beforeGroupRead)($this->groupReads);
            $runtimes = [];
            foreach ($ids as $id) {
                $this->runtimeReads[] = $id->toString();
                if (isset($this->runtimes[$id->toString()])) {
                    $runtimes[$id->toString()] = $this->runtimes[$id->toString()];
                }
            }
            return $runtimes;
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

    public function testBothMovieManifestsDescribeTheDefaultSourceAudioLanguage(): void
    {
        foreach ($this->jobs as $job) {
            $job->updateProbeData(['audioStreams' => [
                ['language' => 'eng', 'codec' => 'aac', 'channels' => 1, 'sampleRate' => 44100, 'isDefault' => false],
                ['language' => 'dan', 'codec' => 'pcm_s16le', 'channels' => 6, 'sampleRate' => 48000, 'isDefault' => true],
            ]]);
        }
        $master = $this->master($this->sign(true)['url']);
        self::assertStringContainsString('TYPE=AUDIO,GROUP-ID="audio",LANGUAGE="dan"', $master);
        self::assertSame(1, substr_count($master, 'TYPE=AUDIO,'));
        self::assertStringNotContainsString('LANGUAGE="eng"', $master);
        $dash = $this->dash($this->sign(true, 'manifest.mpd')['url']);
        self::assertStringContainsString('lang="dan"', $dash);
        self::assertStringNotContainsString('lang="eng"', $dash);
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
        PublishedPresentationFixture::write($sibling->outputDirectory, 3, audio: true, complete: false);
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
        // The sibling leads the cursor far enough to join; only the missing clock keeps it out.
        PublishedPresentationFixture::write($sibling->outputDirectory, 4, audio: true, complete: false);
        $signed = $this->sign(true);
        self::assertSame(2, substr_count($this->master($signed['url']), '#EXT-X-STREAM-INF:'));
    }

    public function testGrowingSiblingJoinsOnlyWithTheJoinLead(): void
    {
        self::assertLessThan(TranscodeSessionSubscriber::RESUME_LEAD_SECONDS, $this->joinLead(),
            'An encoder throttled at its resume lead must still qualify.');
        $this->grow(1, 2);
        self::assertSame(['360p', '1080p'], $this->tiers($this->sign(true)));
        $this->grow(1, 3);
        $signed = $this->sign(true);
        self::assertSame(['360p', '720p', '1080p'], $this->tiers($signed));
        self::assertSame(3, substr_count($this->master($signed['url']), '#EXT-X-STREAM-INF:'));
        $this->cursor(0.5);
        self::assertSame(['360p', '1080p'], $this->tiers($this->sign(true)));
    }

    public function testPrimaryAloneDecidesReadinessAlsoForAPausedSeek(): void
    {
        foreach ([0, 1, 2] as $index) {
            $this->grow($index, 1);
        }
        foreach (['running', 'paused'] as $status) {
            $this->cursor(2.0, $status);
            $signed = $this->sign(true);
            self::assertTrue($signed['ready'], $status);
            self::assertSame($status, $signed['status']);
            self::assertSame(['360p'], $this->tiers($signed));
            self::assertSame(1, substr_count($this->master($signed['url']), '#EXT-X-STREAM-INF:'));
        }
    }

    public function testAttachedSiblingStaysAboveOneSegmentAndIsWithdrawnAtThatMargin(): void
    {
        $this->finishOthers();
        $this->grow(1, 3);
        $attached = $this->sign(true)['renditionSet'];
        self::assertSame(['360p', '720p', '1080p'], $this->tiers(['renditionSet' => $attached]));
        // Eleven seconds in, the sibling leads by seven: enough to stay, not enough to join.
        $this->cursor(11.0);
        self::assertSame(['360p', '720p', '1080p'], $this->tiers($this->sign(true, attachedSet: $attached)));
        self::assertSame(['360p', '1080p'], $this->tiers($this->sign(true)));
        // At one segment of lead it is withdrawn, before the player reaches its edge.
        $this->cursor(12.0);
        $withdrawn = $this->sign(true, attachedSet: $attached);
        self::assertSame(['360p', '1080p'], $this->tiers($withdrawn));
        self::assertTrue($withdrawn['ready']);
    }

    public function testAttachedSetNamingAnotherGenerationDoesNotKeepASibling(): void
    {
        $this->finishOthers();
        $this->grow(1, 3);
        $attached = $this->sign(true)['renditionSet'];
        $this->cursor(11.0);
        $job = array_values($this->jobs)[1];
        $stale = str_replace($job->getPublicId().'.4', $job->getPublicId().'.3', $attached);
        self::assertNotSame($attached, $stale);
        self::assertSame(['360p', '1080p'], $this->tiers($this->sign(true, attachedSet: $stale)));
    }

    public function testSiblingSlowerThanPlaybackIsWithdrawnOnceAndRejoinsOnlyWithTheJoinLead(): void
    {
        // The sibling joined while the cursor stood still, then encodes at half the playback speed.
        $this->finishOthers();
        $segments = 3;
        $this->grow(1, $segments);
        $attached = $this->sign(true)['renditionSet'];
        $changes = [];
        for ($second = 1; $second <= 40; ++$second) {
            if ($second % 12 === 0) {
                $this->grow(1, ++$segments);
            }
            $this->cursor((float) $second);
            $signed = $this->sign(true, attachedSet: $attached);
            self::assertTrue($signed['ready']);
            if ($signed['renditionSet'] !== $attached) {
                $changes[] = ['second' => $second, 'lead' => $segments * 6.0 - $second, 'tiers' => $this->tiers($signed)];
                $attached = $signed['renditionSet'];
            }
        }
        self::assertCount(1, $changes, json_encode($changes, JSON_THROW_ON_ERROR));
        self::assertSame(['360p', '1080p'], $changes[0]['tiers']);
        self::assertGreaterThan(0.0, $changes[0]['lead'], 'The sibling leaves before the player can reach its edge.');
        // Seventeen seconds of lead are not enough to come back; the join lead is.
        $this->grow(1, 10);
        $this->cursor(43.0);
        self::assertSame(['360p', '1080p'], $this->tiers($this->sign(true, attachedSet: $attached)));
        $this->cursor(42.0);
        self::assertSame(['360p', '720p', '1080p'], $this->tiers($this->sign(true, attachedSet: $attached)));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function realTimeSiblings(): iterable
    {
        yield 'one segment ahead, published as the cursor reaches its end' => [1, 0, 2];
        yield 'published only after the cursor has passed its end' => [1, 1, 2];
        yield 'four segments ahead' => [4, 0, 3];
    }

    #[DataProvider('realTimeSiblings')]
    public function testRealTimeSiblingChangesTheSetAtMostOnceOverSixtySignings(int $segments, int $publishedAtSecond, int $members): void
    {
        // Published end and cursor advance together; the lead swings by one segment per publication.
        $this->finishOthers();
        $this->grow(1, $segments);
        $attached = $this->sign(true)['renditionSet'];
        $changes = 0;
        for ($second = 1; $second <= 60; ++$second) {
            if ($second % 6 === $publishedAtSecond) {
                $this->grow(1, ++$segments);
            }
            $this->cursor((float) $second);
            $signed = $this->sign(true, attachedSet: $attached);
            self::assertTrue($signed['ready']);
            if ($signed['renditionSet'] !== $attached) {
                ++$changes;
                $attached = $signed['renditionSet'];
            }
        }
        self::assertLessThanOrEqual(1, $changes);
        self::assertCount($members, $this->tiers(['renditionSet' => $attached]));
    }

    public function testFinishedSiblingJoinsWithoutALeadAndTheLeadIsCappedByTheRemainingDuration(): void
    {
        // Every fixture rendition is finished with two segments: none leads by the join lead.
        self::assertSame(['360p', '720p', '1080p'], $this->tiers($this->sign(true)));
        $this->grow(1, 2);
        self::assertSame(['360p', '1080p'], $this->tiers($this->sign(true)));
        // Twelve published seconds are the whole of a twelve-second source.
        foreach ([0, 1, 2] as $index) {
            $path = $this->runtime($index)->outputDirectory.'/generation.json';
            $metadata = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $metadata['duration'] = 12;
            file_put_contents($path, json_encode($metadata, JSON_THROW_ON_ERROR));
        }
        self::assertSame(['360p', '720p', '1080p'], $this->tiers($this->sign(true)));
    }

    public function testProgressCommittedBetweenRuntimeReadsKeepsTheSet(): void
    {
        $this->grow(1, 4);
        $before = $this->sign(true);
        $this->groupReads = 0;
        $this->runtimeReads = [];
        // The report moves every sibling's cursor in one transaction, after the signing's first read.
        $this->beforeGroupRead = function (int $read): void {
            if ($read === 2) {
                $this->cursor(1.5);
            }
        };
        $after = $this->sign(true, attachedSet: $before['renditionSet']);
        self::assertSame(2, $this->groupReads, 'A signing reads the runtimes of its jobs in two statements.');
        self::assertCount(6, $this->runtimeReads, 'No job runtime is read on its own between them.');
        self::assertSame(1.5, $after['position']);
        self::assertSame($before['renditionSet'], $after['renditionSet']);
        self::assertSame(3, substr_count($this->master($after['url']), '#EXT-X-STREAM-INF:'));
    }

    private function joinLead(): float
    {
        return TranscodeStreamingService::JOIN_LEAD_SEGMENTS * SegmentEncoder::getSegmentDuration();
    }

    /** Gives the primary and the highest rendition two finished minutes, so only the middle one decides a set. */
    private function finishOthers(): void
    {
        foreach ([0, 2] as $index) {
            PublishedPresentationFixture::write($this->runtime($index)->outputDirectory, 20, audio: true);
        }
    }

    private function runtime(int $index): TranscodeRuntimeSnapshot
    {
        return $this->runtimes[array_values($this->jobs)[$index]->getId()->toString()];
    }

    /** Publishes a growing presentation of six-second segments for one of the actor's renditions. */
    private function grow(int $index, int $segments): void
    {
        PublishedPresentationFixture::write($this->runtime($index)->outputDirectory, $segments, audio: true, complete: false);
    }

    /** Moves the cursor of all the actor's renditions, as one playback control does. */
    private function cursor(float $position, string $status = 'running'): void
    {
        foreach ([0, 1, 2] as $index) {
            $runtime = $this->runtime($index);
            $this->runtimes[$runtime->jobId->toString()] = new TranscodeRuntimeSnapshot($runtime->jobId, $runtime->sessionId, $runtime->requestId,
                new DesiredTranscodeState(AudioProfile::streamingStereo(), $status, $position), $runtime->desiredRevision + 1, $runtime->observedRevision,
                $runtime->generation, $runtime->outcome, $runtime->outputDirectory, null, true);
        }
    }

    /**
     * @param array{renditionSet: string} $signed
     * @return list<string> Quality tiers of the signed set, lowest first.
     */
    private function tiers(array $signed): array
    {
        $members = array_map(static fn (string $entry): string => explode('.', $entry)[0], array_filter(explode(',', $signed['renditionSet'])));
        $tiers = [];
        foreach (array_slice(array_values($this->jobs), 0, 3) as $job) {
            if (in_array($job->getPublicId()->toString(), $members, true)) {
                $tiers[] = $job->getQualityTierName();
            }
        }
        return $tiers;
    }

    private function dash(string $url): string
    {
        $response = $this->manifests->dashManifest($this->videoPublic->toString(), Request::create($url));
        self::assertSame(200, $response->getStatusCode());
        ob_start(); $response->sendContent(); return (string) ob_get_clean();
    }

    /** @return array<string, mixed> */
    private function sign(bool $refresh, string $name = 'master.m3u8', int $expiresInSeconds = 86400, ?string $attachedSet = null): array
    {
        $payload = ['requestId' => $this->requestId, 'path' => '/api/transcode/'.$this->videoPublic.'/'.$name, 'refresh' => $refresh, 'expiresInSeconds' => $expiresInSeconds]
            + ($attachedSet === null ? [] : ['attachedSet' => $attachedSet]);
        if ($refresh) {
            $payload['jobIds'] = $this->connection->fetchFirstColumn('SELECT public_id FROM transcode_jobs WHERE owner_user_id = ?', [$this->actor->toString()]);
        }
        $response = $this->signing->sign(Request::create('/api/stream/sign', 'POST', content: json_encode($payload, JSON_THROW_ON_ERROR)));
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
