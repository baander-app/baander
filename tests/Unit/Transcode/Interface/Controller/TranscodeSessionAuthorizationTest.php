<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Interface\Controller\TranscodeSessionController;
use App\Transcode\Interface\Request\UpdateTranscodeSessionRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TranscodeSessionAuthorizationTest extends TestCase
{
    #[DataProvider('sessionOperations')]
    public function testInternalSessionUuidIsRejectedBeforeLookupOrDispatch(string $operation): void
    {
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $sessions->expects(self::never())->method('findByPublicId');
        $sessions->expects(self::never())->method('findByUuid');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $controller = new TranscodeSessionController(
            $this->createStub(Security::class), $bus, $sessions,
            $this->createStub(PlaybackPortInterface::class), new \App\Tests\Unit\Transcode\PublicViewStub(),
        );
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $uuid = (new Uuid())->toString();

        match ($operation) {
            'update' => $controller->update($uuid, new UpdateTranscodeSessionRequest()),
            'updatePosition' => $controller->updatePosition($uuid, Request::create('/', 'POST', content: '{"position":12.5}')),
            default => $controller->$operation($uuid),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function sessionOperations(): iterable
    {
        foreach (['show', 'pause', 'resume', 'cancel', 'update', 'updatePosition'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('operations')]
    public function testSessionAccess(string $operation, string $actor): void
    {
        $owner = new Uuid();
        $session = TranscodeSession::create($owner, new Uuid(), new Uuid(), AudioProfile::streamingStereo());
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($actor === 'anonymous' ? null : new SecurityUser(
            ($actor === 'owner' ? $owner : new Uuid())->toString(), 'user@baander.app', '',
        ));
        $security->method('isGranted')->willReturn($actor === 'admin');
        $allowed = in_array($actor, ['owner', 'admin'], true);
        $port = $this->createMock(TranscodeSessionPortInterface::class);
        $port->method('findByPublicId')->willReturn($session);
        $port->method('findByUuid')->willReturn($session);
        $port->expects($allowed && $operation === 'update' ? $this->once() : $this->never())->method('save');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($allowed && !in_array($operation, ['show', 'update'], true) ? $this->once() : $this->never())
            ->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $controller = new TranscodeSessionController($security, $bus, $port, $this->createStub(PlaybackPortInterface::class), new \App\Tests\Unit\Transcode\PublicViewStub());
        if (!$allowed) {
            $this->expectException($actor === 'anonymous' ? HttpException::class : AccessDeniedException::class);
        }
        $response = match ($operation) {
            'update' => $controller->update($session->getPublicId()->toString(), new UpdateTranscodeSessionRequest()),
            'updatePosition' => $controller->updatePosition($session->getPublicId()->toString(), Request::create('/', 'POST', content: '{"position":12.5,"action":"seek"}')),
            default => $controller->$operation($session->getPublicId()->toString()),
        };
        self::assertSame(200, $response->getStatusCode());
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (['show', 'pause', 'resume', 'cancel', 'update', 'updatePosition'] as $operation) {
            foreach (['anonymous', 'owner', 'unrelated', 'admin'] as $actor) {
                yield "$operation/$actor" => [$operation, $actor];
            }
        }
    }

    #[DataProvider('positions')]
    public function testJsonPosition(string $json, int $status): void
    {
        $owner = new Uuid();
        $session = TranscodeSession::create($owner, new Uuid(), new Uuid(), AudioProfile::streamingStereo());
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($owner->toString(), 'user@baander.app', ''));
        $port = $this->createStub(TranscodeSessionPortInterface::class);
        $port->method('findByPublicId')->willReturn($session);
        $port->method('findByUuid')->willReturn($session);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($status === 200 ? $this->once() : $this->never())->method('dispatch')
            ->willReturnCallback(static function (UpdateTranscodePositionCommand $command) use ($json): Envelope {
                self::assertSame(12.5, $command->position);
                $payload = json_decode($json, true);
                self::assertSame($payload['action'] ?? 'seek', $command->action);
                self::assertSame($payload['generation'] ?? null, $command->expectedGeneration);
                self::assertSame($command->action === 'progress' ? 5 : null, $command->expectedRevision);
                if (isset($payload['requestId'])) {
                    self::assertSame($payload['requestId'], $command->requestId);
                }
                if ($command->action === 'progress') {
                    self::assertSame(3, $command->expectedGeneration);
                    self::assertSame(5, $command->expectedRevision);
                }
                return new Envelope($command);
            });
        $controller = new TranscodeSessionController($security, $bus, $port, $this->createStub(PlaybackPortInterface::class), new \App\Tests\Unit\Transcode\PublicViewStub());
        $response = $controller->updatePosition($session->getPublicId()->toString(), Request::create('/', 'POST', content: $json));
        self::assertSame($status, $response->getStatusCode());
    }

    public function testPositionRetriesKeepTheirIdentityAndDispatchFailuresPropagate(): void
    {
        $owner = Uuid::generate();
        $session = TranscodeSession::create($owner, Uuid::generate(), Uuid::generate(), AudioProfile::streamingStereo());
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($owner->toString(), 'viewer@baander.app', ''));
        $port = $this->createStub(TranscodeSessionPortInterface::class);
        $port->method('findByPublicId')->willReturn($session);
        $requestId = Uuid::generate()->toString();
        $attempts = 0;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(3))->method('dispatch')->willReturnCallback(
            static function (UpdateTranscodePositionCommand $command) use ($requestId, &$attempts): Envelope {
                self::assertSame($requestId, $command->requestId);
                if (++$attempts === 3) {
                    throw new \RuntimeException('Dispatch unavailable');
                }

                return new Envelope($command);
            },
        );
        $controller = new TranscodeSessionController($security, $bus, $port, $this->createStub(PlaybackPortInterface::class), new \App\Tests\Unit\Transcode\PublicViewStub());
        $request = Request::create('/', 'POST', content: json_encode(['position' => 12.5, 'action' => 'pause', 'requestId' => $requestId], JSON_THROW_ON_ERROR));
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $response = $controller->updatePosition($session->getPublicId()->toString(), $request);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(['data' => ['status' => 'ok', 'position' => 12.5, 'requestId' => $requestId]], json_decode((string) $response->getContent(), true));
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dispatch unavailable');
        $controller->updatePosition($session->getPublicId()->toString(), $request);
    }

    /** @return iterable<array{string, int}> */
    public static function positions(): iterable
    {
        yield ['{"position":12.5,"action":"seek"}', 200];
        yield ['{"position":12.5}', 200];
        yield ['{"position":12.5,"action":"seek","generation":3,"revision":99}', 200];
        foreach (['null', '0', '-1', '"3"', '3.5', 'true'] as $generation) {
            yield ['{"position":12.5,"action":"seek","generation":' . $generation . '}', 422];
        }
        yield ['{"position":12.5,"action":"progress","generation":3,"revision":5}', 200];
        yield ['{"position":12.5,"action":"pause","requestId":"01900000-0000-7000-8000-000000000001"}', 200];
        foreach (['null', '""', '"invalid"', '3', '[]'] as $requestId) {
            yield ['{"position":12.5,"requestId":' . $requestId . '}', 422];
        }
        yield ['{"position":12.5,"action":"progress"}', 422];
        yield ['{"position":12.5,"action":"progress","generation":0,"revision":5}', 422];
        yield ['{"position":-1}', 422];
        yield ['{"position":"12"}', 422];
        yield ['{"position":1,"action":"delete"}', 422];
        yield ['{}', 422];
        yield ['null', 400];
        yield ['{', 400];
    }
}
