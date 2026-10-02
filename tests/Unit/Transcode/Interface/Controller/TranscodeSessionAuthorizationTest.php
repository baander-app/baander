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
    #[DataProvider('operations')]
    public function testSessionAccess(string $operation, string $actor): void
    {
        $owner = new Uuid();
        $session = TranscodeSession::create($owner, new Uuid(), new Uuid(), AudioProfile::streamingStereo());
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($actor === 'anonymous' ? null : new SecurityUser(
            ($actor === 'owner' ? $owner : new Uuid())->toString(), 'user@example.test', '',
        ));
        $security->method('isGranted')->willReturn($actor === 'admin');
        $allowed = in_array($actor, ['owner', 'admin'], true);
        $port = $this->createMock(TranscodeSessionPortInterface::class);
        $port->method('findByUuid')->willReturn($session);
        $port->expects($allowed && $operation === 'update' ? $this->once() : $this->never())->method('save');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($allowed && !in_array($operation, ['show', 'update'], true) ? $this->once() : $this->never())
            ->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $controller = new TranscodeSessionController($security, $bus, $port, $this->createStub(PlaybackPortInterface::class));
        if (!$allowed) {
            $this->expectException($actor === 'anonymous' ? HttpException::class : AccessDeniedException::class);
        }
        $response = match ($operation) {
            'update' => $controller->update($session->getId()->toString(), new UpdateTranscodeSessionRequest()),
            'updatePosition' => $controller->updatePosition($session->getId()->toString(), Request::create('/', 'POST', content: '{"position":12.5,"action":"seek"}')),
            default => $controller->$operation($session->getId()->toString()),
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
        $security->method('getUser')->willReturn(new SecurityUser($owner->toString(), 'user@example.test', ''));
        $port = $this->createStub(TranscodeSessionPortInterface::class);
        $port->method('findByUuid')->willReturn($session);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($status === 200 ? $this->once() : $this->never())->method('dispatch')
            ->willReturnCallback(static function (UpdateTranscodePositionCommand $command): Envelope {
                self::assertSame(12.5, $command->position);
                self::assertSame('seek', $command->action);
                return new Envelope($command);
            });
        $controller = new TranscodeSessionController($security, $bus, $port, $this->createStub(PlaybackPortInterface::class));
        $response = $controller->updatePosition($session->getId()->toString(), Request::create('/', 'POST', content: $json));
        self::assertSame($status, $response->getStatusCode());
    }

    /** @return iterable<array{string, int}> */
    public static function positions(): iterable
    {
        yield ['{"position":12.5,"action":"seek"}', 200];
        yield ['{"position":-1}', 422];
        yield ['{"position":"12"}', 422];
        yield ['{"position":1,"action":"delete"}', 422];
        yield ['{}', 422];
        yield ['null', 400];
        yield ['{', 400];
    }
}
