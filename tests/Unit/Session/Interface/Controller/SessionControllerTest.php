<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Session\Application\Command\ClaimSessionCommand;
use App\Session\Application\Command\CreateSessionCommand;
use App\Session\Application\Command\SyncSessionCommand;
use App\Session\Application\Port\SessionPortInterface;
use App\Session\Interface\Controller\SessionController;
use App\Session\Interface\Request\ClaimSessionRequest;
use App\Session\Interface\Request\CreateSessionRequest;
use App\Session\Interface\Request\SyncSessionRequest;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class SessionControllerTest extends TestCase
{
    private Security&MockObject $security;
    private SessionPortInterface&MockObject $sessionPort;
    private MessageBusInterface&MockObject $commandBus;
    private SessionController $controller;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->sessionPort = $this->createMock(SessionPortInterface::class);
        $this->commandBus = $this->createMock(MessageBusInterface::class);
        $this->controller = new SessionController(
            $this->security,
            $this->sessionPort,
            $this->commandBus,
        );
    }

    public function testGetReturnsSession(): void
    {
        $userId = Uuid::v7()->toString();
        $user = new SecurityUser($userId, 'test@example.com', 'hash');
        $this->security->method('getUser')->willReturn($user);

        $sessionData = [
            'id' => Uuid::v7()->toString(),
            'userId' => $userId,
            'queue' => ['track1', 'track2'],
            'currentTrackIndex' => 0,
            'position' => 12.5,
            'playbackState' => 'playing',
        ];

        $this->sessionPort->method('getSession')->willReturn($sessionData);

        $response = $this->controller->get();
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($sessionData, $data['data']);
    }

    public function testGetReturns404WhenNoSession(): void
    {
        $user = new SecurityUser(Uuid::v7()->toString(), 'test@example.com', 'hash');
        $this->security->method('getUser')->willReturn($user);

        $this->sessionPort->method('getSession')->willReturn(null);

        $response = $this->controller->get();

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testGetReturns401WhenNotAuthenticated(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $response = $this->controller->get();

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testSyncDispatchesCommandAndReturnsResult(): void
    {
        $user = new SecurityUser(Uuid::v7()->toString(), 'test@example.com', 'hash');
        $this->security->method('getUser')->willReturn($user);

        $deviceId = Uuid::v7()->toString();
        $request = new Request();
        $request->headers->set('X-Device-Id', $deviceId);

        $payload = new SyncSessionRequest(
            queue: ['track1'],
            currentTrackIndex: 0,
            position: 5.0,
            playbackState: 'playing',
        );

        $resultData = ['id' => 'session-id', 'playbackState' => 'playing'];
        $stamp = new HandledStamp($resultData, 'handler');

        $this->commandBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SyncSessionCommand::class))
            ->willReturn(new Envelope(new \stdClass(), [$stamp]));

        $response = $this->controller->sync($request, $payload);
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($resultData, $data['data']);
    }

    public function testSyncReturns422WhenMissingDeviceId(): void
    {
        $user = new SecurityUser(Uuid::v7()->toString(), 'test@example.com', 'hash');
        $this->security->method('getUser')->willReturn($user);

        $request = new Request();
        $payload = new SyncSessionRequest();

        $response = $this->controller->sync($request, $payload);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testClaimDispatchesCommand(): void
    {
        $user = new SecurityUser(Uuid::v7()->toString(), 'test@example.com', 'hash');
        $this->security->method('getUser')->willReturn($user);

        $payload = new ClaimSessionRequest(deviceId: Uuid::v7()->toString());

        $resultData = ['id' => 'session-id', 'activeDeviceId' => $payload->deviceId];
        $stamp = new HandledStamp($resultData, 'handler');

        $this->commandBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ClaimSessionCommand::class))
            ->willReturn(new Envelope(new \stdClass(), [$stamp]));

        $response = $this->controller->claim($payload);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNewCreatesSessionAndReturns201(): void
    {
        $user = new SecurityUser(Uuid::v7()->toString(), 'test@example.com', 'hash');
        $this->security->method('getUser')->willReturn($user);

        $payload = new CreateSessionRequest(
            queue: ['track1', 'track2'],
            currentTrackIndex: 0,
            position: 0.0,
        );

        $resultData = ['id' => 'new-session-id', 'playbackState' => 'stopped'];
        $stamp = new HandledStamp($resultData, 'handler');

        $this->commandBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CreateSessionCommand::class))
            ->willReturn(new Envelope(new \stdClass(), [$stamp]));

        $response = $this->controller->new($payload);

        $this->assertSame(201, $response->getStatusCode());
    }
}
