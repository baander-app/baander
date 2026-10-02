<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Party\Application\Command\CreatePartySessionCommand;
use App\Party\Application\Command\JoinPartySessionCommand;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Interface\Controller\PartySessionController;
use App\Party\Interface\Request\CreatePartySessionRequest;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Validation test: command-bus dispatch() returns an Envelope, not the handler
 * result. Controllers must unwrap the HandledStamp before passing the result to
 * resource mappers. The current PartySessionController passes the Envelope
 * directly, so these tests fail.
 */
final class PartySessionControllerTest extends TestCase
{
    private MessageBusInterface&MockObject $commandBus;
    private PartySessionPortInterface $sessionPort;
    private PartyMemberPortInterface $memberPort;
    private PartySessionController $controller;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(MessageBusInterface::class);
        $this->sessionPort = $this->createStub(PartySessionPortInterface::class);
        $this->memberPort = $this->createStub(PartyMemberPortInterface::class);

        $this->controller = new PartySessionController(
            $this->commandBus,
            $this->sessionPort,
            $this->memberPort,
        );
    }

    public function testCreateUnwrapsEnvelopeBeforeMappingResource(): void
    {
        $user = new SecurityUser(Uuid::v7()->toString(), 'host@example.com', 'hash');
        $session = SyncedPartySession::create(
            hostUserId: Uuid::fromString($user->getId()),
            videoId: Uuid::v7(),
            transcodeJobId: Uuid::v7(),
            maxMembers: 5,
        );

        $this->commandBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CreatePartySessionCommand::class))
            ->willReturn(new Envelope(new \stdClass(), [new HandledStamp($session, 'handler')]));

        $payload = new CreatePartySessionRequest(
            videoId: $session->getVideoId()->toString(),
            transcodeJobId: $session->getTranscodeJobId()->toString(),
            maxMembers: 5,
        );

        $response = $this->controller->create($payload, $user);
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($session->getId()->toString(), $data['data']['uuid']);
        $this->assertSame($session->getHostUserId()->toString(), $data['data']['hostUserId']);
    }

    public function testJoinUnwrapsEnvelopeBeforeMappingResource(): void
    {
        $user = new SecurityUser(Uuid::v7()->toString(), 'member@example.com', 'hash');
        $sessionId = Uuid::v7();
        $member = PartyMember::create(
            userId: Uuid::fromString($user->getId()),
            sessionId: $sessionId,
        );

        $this->commandBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(JoinPartySessionCommand::class))
            ->willReturn(new Envelope(new \stdClass(), [new HandledStamp($member, 'handler')]));

        $response = $this->controller->join($sessionId->toString(), $user);
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($member->getId()->toString(), $data['data']['uuid']);
        $this->assertSame($user->getId(), $data['data']['userId']);
    }
}
