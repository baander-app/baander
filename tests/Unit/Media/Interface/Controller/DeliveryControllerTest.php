<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Interface\Controller;

use App\Media\Application\DTO\DeliveryDecision;
use App\Media\Application\DTO\DeliveryDestination;
use App\Media\Application\DTO\DeliveryKind;
use App\Media\Application\Port\DeliveryDecisionPortInterface;
use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Interface\Controller\DeliveryController;
use App\Media\Interface\Request\DeliveryRequest;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\ValueObject\MediaReadScope;
use App\Transcode\Application\DTO\CompletedVideoArtifact;
use App\Transcode\Application\Port\CompletedVideoDeliveryPort;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeliveryControllerTest extends TestCase
{
    public function testRenegotiationPassesTheReportedSourceFailureToTheDeliveryPolicy(): void
    {
        $owner = new Uuid();
        $track = new PublicId();
        $delivery = $this->createMock(DeliveryDecisionPortInterface::class);
        $delivery->expects(self::once())->method('track')
            ->with($owner, $track, self::callback(static fn (DeliveryDestination $destination): bool => $destination->sourcePlaybackFailed))
            ->willReturn(new DeliveryDecision(DeliveryKind::Incompatible, 'browser_rejected_source'));
        $scopes = $this->createStub(MediaReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(MediaReadScope::authenticated($owner, LibraryReadScope::unrestricted()));
        $controller = new DeliveryController($delivery, $scopes, new HmacStreamAuthAdapter('delivery-test-secret'), $this->createStub(CompletedVideoDeliveryPort::class));

        $response = $controller->decide(new DeliveryRequest('track', $track->toString(), ['mp3'], sourcePlaybackFailed: true));
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('incompatible', $body['kind']);
        self::assertSame('browser_rejected_source', $body['reason']);
    }

    #[DataProvider('retentionResults')]
    public function testCompletedVideoMustBeRetainedForTheSignedLifetime(bool $retained): void
    {
        $job = new PublicId();
        $path = '/api/transcode/artifacts/' . $job . '/3/master.m3u8';
        $artifact = new CompletedVideoArtifact($path, 'mp4', 'h264', 'aac', 640, 360, 2, 0, 1, 8, false, 'hls', 'yuv420p', $job, 3);
        $delivery = $this->createStub(DeliveryDecisionPortInterface::class);
        $delivery->method('video')->willReturn(new DeliveryDecision(DeliveryKind::Cached, url: $path, artifact: $artifact));
        $scopes = $this->createStub(MediaReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(MediaReadScope::authenticated(new Uuid(), LibraryReadScope::unrestricted()));
        $expiry = null;
        $completed = $this->createMock(CompletedVideoDeliveryPort::class);
        $completed->expects(self::once())->method('grant')->with($job, 3, self::callback(static function (int $value) use (&$expiry): bool {
            $expiry = $value;
            return $value > time();
        }))->willReturn($retained);
        $controller = new DeliveryController($delivery, $scopes, new HmacStreamAuthAdapter('delivery-test-secret'), $completed);

        $response = $controller->decide(new DeliveryRequest('video', new PublicId()->toString(), ['mp4'], requestId: new Uuid()->toString()));
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($retained ? 200 : 503, $response->getStatusCode());
        if ($retained) {
            parse_str(parse_url($body['url'], PHP_URL_QUERY), $query);
            self::assertSame($expiry, (int) $query['exp']);
        } else {
            self::assertArrayNotHasKey('url', $body);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function retentionResults(): iterable
    {
        yield 'retained' => [true];
        yield 'removed before retention' => [false];
    }

    public function testVideoDeliveryKeepsTheClientSessionIdentityWhenSigningItsUrl(): void
    {
        $owner = new Uuid();
        $video = new PublicId();
        $requestId = new Uuid()->toString();
        $delivery = $this->createMock(DeliveryDecisionPortInterface::class);
        $delivery->expects(self::once())->method('video')
            ->with($owner, $video, self::anything(), $requestId)
            ->willReturn(new DeliveryDecision(DeliveryKind::Original));
        $scopes = $this->createStub(MediaReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(MediaReadScope::authenticated($owner, LibraryReadScope::unrestricted()));
        $controller = new DeliveryController($delivery, $scopes, new HmacStreamAuthAdapter('delivery-test-secret'), $this->createStub(CompletedVideoDeliveryPort::class));

        $response = $controller->decide(new DeliveryRequest('video', $video->toString(), ['mp4'], requestId: $requestId));
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($requestId, $body['requestId']);
        self::assertStringContainsString('&sig=', $body['url']);
    }

    public function testVideoDeliveryCannotAdmitAnUnidentifiedClient(): void
    {
        $delivery = $this->createMock(DeliveryDecisionPortInterface::class);
        $delivery->expects(self::never())->method('video');
        $scopes = $this->createStub(MediaReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(MediaReadScope::authenticated(new Uuid(), LibraryReadScope::unrestricted()));
        $controller = new DeliveryController($delivery, $scopes, new HmacStreamAuthAdapter('delivery-test-secret'), $this->createStub(CompletedVideoDeliveryPort::class));

        $response = $controller->decide(new DeliveryRequest('video', new PublicId()->toString(), ['mp4']));

        self::assertSame(400, $response->getStatusCode());
    }

    #[DataProvider('renditionDeliveries')]
    public function testRenditionUrlSignsItsEntirePresentationIdentity(DeliveryKind $kind, string $parameters, string $mediaKind): void
    {
        $owner = new Uuid();
        $track = new PublicId();
        $path = $mediaKind === 'track'
            ? '/api/stream/original/track?' . http_build_query(['id' => $track->toString(), 'owner' => $owner->toString()]) . '&' . $parameters
            : '/api/transcode/' . $track->toString() . '/master.m3u8?' . $parameters;
        $delivery = $this->createStub(DeliveryDecisionPortInterface::class);
        $artifact = $mediaKind === 'video'
            ? new CompletedVideoArtifact($path, 'mp4', 'h264', 'aac', 640, 360, 2, 0, 1, 8, false, 'hls', 'yuv420p', $track, 3)
            : null;
        $delivery->method($mediaKind)->willReturn(new DeliveryDecision($kind, url: $path, artifact: $artifact));
        $scopes = $this->createStub(MediaReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(MediaReadScope::authenticated($owner, LibraryReadScope::unrestricted()));
        $signing = new HmacStreamAuthAdapter('delivery-test-secret', 'https://baander.app');
        $completed = $this->createStub(CompletedVideoDeliveryPort::class);
        $completed->method('grant')->willReturn(true);
        $controller = new DeliveryController($delivery, $scopes, $signing, $completed);

        $response = $controller->decide(new DeliveryRequest($mediaKind, $track->toString(), ['mp4'], audioCodecs: ['aac'], requestId: new Uuid()->toString()));
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($kind->value, $body['kind']);
        self::assertStringStartsWith('https://baander.app' . $path . '&sig=', $body['url']);
        parse_str(parse_url($body['url'], PHP_URL_QUERY), $query);
        self::assertTrue($signing->validateUrl($path, $query['sig'], (int) $query['exp']));
        self::assertFalse($signing->validateUrl($path . '&index=9', $query['sig'], (int) $query['exp']));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    /** @return iterable<string, array{DeliveryKind, string, string}> */
    public static function renditionDeliveries(): iterable
    {
        yield 'copied audio' => [DeliveryKind::Copy, 'format=aac&mode=copy&container=mp4&index=2', 'track'];
        yield 'completed encoded audio' => [DeliveryKind::Cached, 'format=aac&bitrate=128000', 'track'];
        yield 'completed video' => [DeliveryKind::Cached, 'set=0123456789abABCD_-xyz.3', 'video'];
    }
}
