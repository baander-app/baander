<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadataFactory;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Controller\UserValueResolver;

final class PartySessionControllerTest extends TestCase
{
    private MessageBusInterface&MockObject $commandBus;
    private PartySessionPortInterface&Stub $sessionPort;
    private PartyMemberPortInterface $memberPort;
    private PartySessionController $controller;
    private \App\Party\Application\Port\PartyPublicIdentityPortInterface&Stub $identities;
    private PublicId $hostPublicId;
    private PublicId $videoPublicId;
    private PublicId $jobPublicId;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(MessageBusInterface::class);
        $this->sessionPort = $this->createStub(PartySessionPortInterface::class);
        $this->memberPort = $this->createStub(PartyMemberPortInterface::class);

        $this->hostPublicId = new PublicId();
        $this->videoPublicId = new PublicId();
        $this->jobPublicId = new PublicId();
        $this->identities = $this->createStub(\App\Party\Application\Port\PartyPublicIdentityPortInterface::class);
        $this->identities->method('session')->willReturnCallback(fn (SyncedPartySession $session) => new \App\Party\Application\DTO\PartySessionView($session, $this->hostPublicId, $this->videoPublicId, $this->jobPublicId));
        $this->identities->method('member')->willReturnCallback(fn (PartyMember $member) => new \App\Party\Application\DTO\PartyMemberView($member, $this->hostPublicId, new PublicId()));

        $this->controller = new PartySessionController(
            $this->commandBus,
            $this->sessionPort,
            $this->identities,
            $this->memberPort,
        );
    }

    public function testCreateUnwrapsEnvelopeBeforeMappingResource(): void
    {
        $user = $this->principal();
        $session = SyncedPartySession::create(
            hostUserId: Uuid::fromString($user->getId()),
            videoId: Uuid::v7(),
            transcodeJobId: Uuid::v7(),
            maxMembers: 5,
        );

        $this->identities->method('videoId')->willReturn($session->getVideoId());
        $this->identities->method('transcodeJobId')->willReturn($session->getTranscodeJobId());
        $this->commandBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (CreatePartySessionCommand $command): bool => $command->getVideoId()->equals($session->getVideoId()) && $command->getTranscodeJobId()?->equals($session->getTranscodeJobId()) === true))
            ->willReturn(new Envelope(new \stdClass(), [new HandledStamp($session, 'handler')]));

        $payload = new CreatePartySessionRequest(
            videoId: $this->videoPublicId->toString(),
            transcodeJobId: $this->jobPublicId->toString(),
            maxMembers: 5,
        );

        $response = $this->controller->create($payload, $user);
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($session->getPublicId()->toString(), $data['data']['publicId']);
        $this->assertArrayNotHasKey('uuid', $data['data']);
        $this->assertSame($this->hostPublicId->toString(), $data['data']['hostUserId']);
    }

    public function testJoinUnwrapsEnvelopeBeforeMappingResource(): void
    {
        $user = $this->principal();
        $session = SyncedPartySession::create(Uuid::fromString($user->getId()), new Uuid(), null);
        $sessionId = $session->getId();
        $this->sessionPort->method('findByPublicIdentifier')->willReturn($session);
        $member = PartyMember::create(
            userId: Uuid::fromString($user->getId()),
            sessionId: $sessionId,
        );

        $this->commandBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(JoinPartySessionCommand::class))
            ->willReturn(new Envelope(new \stdClass(), [new HandledStamp($member, 'handler')]));

        $response = $this->controller->join($session->getPublicId()->toString(), $user);
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($member->getPublicId()->toString(), $data['data']['publicId']);
        $this->assertArrayNotHasKey('uuid', $data['data']);
        $this->assertSame($this->hostPublicId->toString(), $data['data']['userId']);
    }

    public function testEveryPartyMutationResolvesThePublicIdentityContract(): void
    {
        $this->commandBus->expects($this->never())->method('dispatch');
        $user = $this->principal();
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));
        $resolver = new UserValueResolver($storage);
        $metadata = new ArgumentMetadataFactory();

        foreach (['create', 'join', 'leave', 'sync', 'end'] as $method) {
            $arguments = $metadata->createArgumentMetadata([$this->controller, $method]);
            $userArgument = array_values(array_filter($arguments, static fn ($argument): bool => $argument->getName() === 'user'))[0];

            self::assertSame(AuthenticatedUserIdentityInterface::class, $userArgument->getType());
            self::assertSame([$user], $resolver->resolve(Request::create('/api/party/sessions'), $userArgument));
        }
    }

    public function testPartyMutationStillRequiresAnAuthenticatedPrincipal(): void
    {
        $this->commandBus->expects($this->never())->method('dispatch');
        $arguments = (new ArgumentMetadataFactory())->createArgumentMetadata([$this->controller, 'join']);
        $userArgument = array_values(array_filter($arguments, static fn ($argument): bool => $argument->getName() === 'user'))[0];
        $this->expectException(AccessDeniedException::class);

        (new UserValueResolver(new TokenStorage()))->resolve(Request::create('/api/party/sessions'), $userArgument);
    }

    public function testInternalSessionUuidCannotAddressAnyPublicPartyAction(): void
    {
        $this->commandBus->expects($this->never())->method('dispatch');
        $user = $this->principal();
        $id = (new Uuid())->toString();
        self::assertSame(404, $this->controller->show($id)->getStatusCode());
        self::assertSame(404, $this->controller->join($id, $user)->getStatusCode());
        self::assertSame(404, $this->controller->leave($id, $user)->getStatusCode());
        self::assertSame(404, $this->controller->end($id, $user)->getStatusCode());
    }

    private function principal(): AuthenticatedUserIdentityInterface&UserInterface
    {
        $user = $this->createStubForIntersectionOfInterfaces([AuthenticatedUserIdentityInterface::class, UserInterface::class]);
        $user->method('getId')->willReturn((new Uuid())->toString());
        $user->method('getRoles')->willReturn(['ROLE_USER']);
        $user->method('getUserIdentifier')->willReturn('member@baander.app');

        self::assertInstanceOf(UserInterface::class, $user);
        self::assertInstanceOf(AuthenticatedUserIdentityInterface::class, $user);

        return $user;
    }

}
