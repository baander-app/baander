<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Query\TranscodeSessionQueryPort;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Interface\Controller\TranscodeSessionController;
use App\Transcode\Interface\Request\CreateTranscodeSessionRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Core\User\UserInterface;

final class TranscodeSessionIdentityTest extends TestCase
{
    #[DataProvider('invalidIdentities')]
    public function testInvalidIdentityIsUnauthorizedBeforeAnyApplicationAccess(string $action, bool $anonymous): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($anonymous ? null : $this->createStub(UserInterface::class));
        $bus = $this->createMock(MessageBusInterface::class);
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $playback = $this->createMock(PlaybackPortInterface::class);
        $query = $this->createMock(TranscodeSessionQueryPort::class);
        $this->expectNoCalls($bus, MessageBusInterface::class);
        $this->expectNoCalls($sessions, TranscodeSessionPortInterface::class);
        $this->expectNoCalls($playback, PlaybackPortInterface::class);
        $this->expectNoCalls($query, TranscodeSessionQueryPort::class);
        $controller = new TranscodeSessionController($security, $bus, $sessions, $playback);

        $response = match ($action) {
            'create' => $controller->create(new CreateTranscodeSessionRequest(videoId: Uuid::generate()->toString())),
            'index' => $controller->index(),
            'listSessions' => $controller->listSessions($query),
            default => throw new \LogicException('Unknown test action.'),
        };

        self::assertSame(401, $response->getStatusCode());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function invalidIdentities(): iterable
    {
        foreach (['create', 'index', 'listSessions'] as $action) {
            yield $action . '/anonymous' => [$action, true];
            yield $action . '/no UUID identity' => [$action, false];
        }
    }

    #[DataProvider('listActions')]
    public function testValidIdentityListsUsingItsUuid(string $action): void
    {
        $owner = Uuid::generate();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($owner->toString(), 'viewer@baander.app', ''));
        $bus = $this->createMock(MessageBusInterface::class);
        $playback = $this->createMock(PlaybackPortInterface::class);
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $query = $this->createMock(TranscodeSessionQueryPort::class);
        $this->expectNoCalls($bus, MessageBusInterface::class);
        $this->expectNoCalls($playback, PlaybackPortInterface::class);
        if ($action === 'index') {
            $this->expectNoCalls($query, TranscodeSessionQueryPort::class);
            $sessions->expects(self::once())->method('findActiveByUser')->with($owner)->willReturn([]);
        } else {
            $this->expectNoCalls($sessions, TranscodeSessionPortInterface::class);
            $query->expects(self::once())->method('findByUser')->with($owner)->willReturn([]);
        }
        $controller = new TranscodeSessionController($security, $bus, $sessions, $playback);

        $response = $action === 'index' ? $controller->index() : $controller->listSessions($query);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['data' => []], json_decode((string) $response->getContent(), true));
    }

    /** @return iterable<array{string}> */
    public static function listActions(): iterable
    {
        yield ['index'];
        yield ['listSessions'];
    }

    public function testValidIdentityCreatesSessionWithItsUuid(): void
    {
        $owner = Uuid::generate();
        $video = Uuid::generate();
        $session = TranscodeSession::create($owner, Uuid::generate(), $video, AudioProfile::streamingStereo());
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($owner->toString(), 'viewer@baander.app', ''));
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $this->expectNoCalls($sessions, TranscodeSessionPortInterface::class);
        $playback = $this->createMock(PlaybackPortInterface::class);
        $playback->expects(self::once())->method('assertAccess')->with($video);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (CreateTranscodeSessionCommand $command) use ($owner, $video, $session): Envelope {
                self::assertTrue($command->getUserId()->equals($owner));
                self::assertTrue($command->getVideoId()->equals($video));
                self::assertSame('720p', $command->getQualityTier()->name);

                return new Envelope($command, [new HandledStamp($session, 'test-handler')]);
            },
        );
        $controller = new TranscodeSessionController($security, $bus, $sessions, $playback);

        $response = $controller->create(new CreateTranscodeSessionRequest(videoId: $video->toString(), qualityTier: '720p'));

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame($session->getId()->toString(), $body['uuid']);
    }

    /** @param class-string $contract */
    private function expectNoCalls(MockObject $mock, string $contract): void
    {
        foreach ((new ReflectionClass($contract))->getMethods() as $method) {
            $mock->expects(self::never())->method($method->getName());
        }
    }
}
