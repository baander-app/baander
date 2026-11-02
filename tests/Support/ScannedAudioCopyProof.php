<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Media\Application\DTO\DeliveryDestination;
use App\Media\Application\DTO\DeliveryKind;
use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Application\Service\DeliveryCompatibility;
use App\Media\Application\Service\DeliveryDecisionService;
use App\Media\Interface\Controller\OriginalMediaController;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Infrastructure\Doctrine\AudioRenditionRuntime;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Transcode\AudioRenditionCache;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

trait ScannedAudioCopyProof
{
    private function assertScannedAudioCopy(ContainerInterface $container, Connection $connection, string $schema, string $root, Uuid $library, PublicId $track, string $source): void
    {
        $owner = Uuid::generate();
        $connection->executeStatement("INSERT INTO users (id, public_id, name, email, password, created_at, updated_at) VALUES (?, ?, 'Audio listener', 'copy@baander.app', 'hash', clock_timestamp(), clock_timestamp())", [$owner->toString(), (new PublicId())->toString()]);
        $connection->executeStatement('INSERT INTO user_library_access (user_id, library_id) VALUES (?, ?)', [$owner->toString(), $library->toString()]);
        $streams = $container->get(StreamPortInterface::class);
        $scopes = $container->get(MediaReadScopeProviderInterface::class);
        $access = $container->get(PlaybackMediaAccessInterface::class);
        $videos = $container->get(VideoPortInterface::class);
        self::assertInstanceOf(StreamPortInterface::class, $streams);
        self::assertInstanceOf(MediaReadScopeProviderInterface::class, $scopes);
        self::assertInstanceOf(PlaybackMediaAccessInterface::class, $access);
        self::assertInstanceOf(VideoPortInterface::class, $videos);
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(static fn (string $key): mixed => $key === TranscodeSettingDefinitions::ENABLED ? false : 192);
        $profile = new DeploymentProfile('household');
        $policy = new MediaExecutionPolicy($profile);
        $runtime = new AudioRenditionRuntime($connection);
        $cache = new AudioRenditionCache($policy, new SegmentFileResolver($root . '/copies'), $runtime, new NullLogger(), pollIntervalSeconds: .005, startTimeoutSeconds: 5);
        $delivery = new DeliveryDecisionService($access, $scopes, $videos, $streams, new DeliveryCompatibility($policy), $policy, $cache, $settings);
        $decision = $delivery->track($owner, $track, new DeliveryDestination(['mp4'], audioCodecs: ['aac']));
        self::assertSame(DeliveryKind::Copy, $decision->kind);
        self::assertNotNull($decision->url);
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM audio_rendition_runtime'));
        $signatures = new HmacStreamAuthAdapter('scanned-audio-copy-secret');
        $signed = $signatures->signUrl($decision->url)['url'];
        $controller = new OriginalMediaController($policy, $settings, $scopes, $access, $videos, $streams, $cache, $signatures);
        $native = new NativeExecutionFixture('baander.app:test', str_repeat('b', 32), profile: $profile);
        $worker = new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/Media/audio-rendition-worker.php', $schema, '.05'], env: [
            'BAANDER_DEPLOYMENT_PROFILE' => $profile->identifier,
            'BAANDER_DEPLOYMENT_PROFILE_JSON' => $profile->toJson(),
            'BAANDER_NATIVE_SOCKET' => $native->socketPath(),
            'BAANDER_WORKER_NAMESPACE' => 'baander.app:test',
            'BAANDER_WORKER_BOOT_ID' => str_repeat('b', 32),
            'BAANDER_WORKER_LEASE_EPOCH' => '1',
        ]);
        try {
            $worker->start();
            $request = Request::create($signed);
            $request->headers->set('Range', 'bytes=0-15');
            $response = $controller->track($request);
            $response->prepare($request);
            self::assertSame(206, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame('audio/mp4', $response->headers->get('Content-Type'));
            self::assertSame('bytes', $response->headers->get('Accept-Ranges'));
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $row = $connection->fetchAssociative('SELECT state, mode, bitrate, stream_index, source_hash, final_path FROM audio_rendition_runtime');
            self::assertNotFalse($row);
            self::assertSame('complete', $row['state']);
            self::assertSame('copy', $row['mode']);
            self::assertNull($row['bitrate']);
            self::assertSame(0, $row['stream_index']);
            self::assertSame(hash_file('xxh3', $source), $row['source_hash']);
            $roundtrip = $root . '/roundtrip.aac';
            $convert = new Process(['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-i', $row['final_path'], '-map', '0:a:0', '-c:a', 'copy', '-f', 'adts', $roundtrip]);
            $convert->mustRun();
            self::assertSame(hash_file('sha256', $source), hash_file('sha256', $roundtrip));
            $reused = $delivery->track($owner, $track, new DeliveryDestination(['mp4'], audioCodecs: ['aac']));
            self::assertSame(DeliveryKind::Cached, $reused->kind);
            self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM audio_rendition_runtime'));
        } finally {
            $worker->stop(.2);
            $native->close();
        }
    }
}
