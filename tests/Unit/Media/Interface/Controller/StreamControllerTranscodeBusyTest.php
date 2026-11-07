<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Interface\Controller;

use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Domain\Model\TrackStreamMetadata;
use App\Media\Interface\Controller\StreamController;
use App\Shared\Application\Http\RequestLocale;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\ValueObject\MediaReadScope;
use App\Transcode\Application\Exception\AudioRenditionBusyException;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionPortInterface;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * When every audio slot stays busy for the whole start wait, a listener who
 * needs a new rendition learns why and when to ask again; the work stays queued.
 */
final class StreamControllerTranscodeBusyTest extends TestCase
{
    private string $directory;
    private string $file;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-stream-busy-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->file = $this->directory . '/track.flac';
        self::assertNotFalse(file_put_contents($this->file, 'original audio'));
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        rmdir($this->directory);
    }

    public function testBusyAudioSlotsAnswer503WithRetryAfterAndATranslatedReason(): void
    {
        $renditions = $this->createMock(AudioRenditionPortInterface::class);
        // The server cap applies before the bitrate snaps to the ladder.
        $renditions->expects(self::once())
            ->method('open')
            ->with(self::anything(), $this->file, AudioRenditionFormat::Opus, 192_000)
            ->willThrowException(new AudioRenditionBusyException('Every audio slot stayed busy for the whole start wait.'));

        $request = Request::create('https://api.baander.app/api/stream/track?id=' . (new PublicId())->toString() . '&format=opus&bitrate=200000');
        $request->attributes->set(RequestLocale::ATTRIBUTE, 'da');

        $response = $this->controller($renditions, $request)->streamById($request);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->headers->get('Retry-After'));
        $error = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($error);
        self::assertSame('Alle lydpladser er optaget. Dit nummer bliver i køen; prøv igen om lidt.', $error['error']['message'] ?? null);
        self::assertSame(['reason' => 'audio_slots_busy'], $error['error']['details'] ?? null);
    }

    private function controller(AudioRenditionPortInterface $renditions, Request $request): StreamController
    {
        $stream = $this->createStub(StreamPortInterface::class);
        $stream->method('getLibraryIdForTrack')->willReturn(new Uuid());
        $stream->method('resolveTrackPath')->willReturn($this->file);
        $stream->method('getTrackMetadata')->willReturn(new TrackStreamMetadata(
            'track', 'track.flac', $this->file, 'audio/flac', 14,
            null, null, null, null, null,
        ));
        $scopes = $this->createStub(MediaReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(MediaReadScope::authenticated(new Uuid(), LibraryReadScope::unrestricted()));
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnMap([
            [TranscodeSettingDefinitions::ENABLED, true],
            [TranscodeSettingDefinitions::MAX_BITRATE, 192],
        ]);

        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/media+intl-icu.da.yaml', 'da', 'media+intl-icu');
        $requests = new RequestStack();
        $requests->push($request);
        $controller = new StreamController(new \App\Transcode\Application\Service\MediaExecutionPolicy(new \App\Shared\Application\DTO\DeploymentProfile()), $stream, $scopes, $renditions, $settings);
        $controller->setTranslator($translator);
        $controller->setTranslationRequests($requests);

        return $controller;
    }
}
