<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Interface\Controller;

use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Domain\Model\TrackStreamMetadata;
use App\Media\Interface\Controller\StreamController;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\ValueObject\MediaReadScope;
use App\Transcode\Application\Port\AudioRendition;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionPortInterface;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class StreamControllerCopyTest extends TestCase
{
    public function testAuthorizedCopyReturnsMp4ByteRangeWithTrustedSelection(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'baander-audio-copy-');
        self::assertIsString($file);
        file_put_contents($file, '0123456789');
        try {
            $id = new PublicId();
            $streams = $this->createStub(StreamPortInterface::class);
            $streams->method('getLibraryIdForTrack')->willReturn(new Uuid());
            $streams->method('resolveTrackPath')->willReturn($file);
            $streams->method('getTrackMetadata')->willReturn(new TrackStreamMetadata($id->toString(), 'track.aac', $file, 'audio/aac', 10,
                'aac', 128000, 48000, 2, 1.0, 'aac', 3, '0123456789abcdef'));
            $scope = $this->createStub(MediaReadScopeProviderInterface::class);
            $scope->method('current')->willReturn(MediaReadScope::authenticated(new Uuid(), LibraryReadScope::unrestricted()));
            $audio = $this->createMock(AudioRenditionPortInterface::class);
            $audio->expects(self::never())->method('open');
            $audio->expects(self::once())->method('openCopy')->with($id->toString(), $file, '0123456789abcdef', 3)
                ->willReturn(AudioRendition::complete(AudioRenditionFormat::Aac, $file, 'audio/mp4'));
            $controller = new StreamController(new MediaExecutionPolicy(new DeploymentProfile('household')), $streams, $scope, $audio, $this->createStub(SystemSettingsPortInterface::class));
            $translator = $this->createStub(TranslatorInterface::class);
            $translator->method('trans')->willReturnArgument(0);
            $controller->setTranslator($translator);
            $request = Request::create('https://api.baander.app/api/stream/track?' . http_build_query([
                'id' => $id->toString(), 'format' => 'aac', 'mode' => 'copy', 'container' => 'mp4', 'index' => 3,
            ]));
            $request->headers->set('Range', 'bytes=2-5');
            $response = $controller->streamById($request);
            $response->prepare($request);
            self::assertSame(206, $response->getStatusCode());
            self::assertSame('audio/mp4', $response->headers->get('Content-Type'));
            self::assertSame('bytes 2-5/10', $response->headers->get('Content-Range'));
            ob_start();
            $response->sendContent();
            self::assertSame('2345', ob_get_clean());
        } finally {
            unlink($file);
        }
    }
}
