<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Party\Application\Command\TransferHostCommand;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Model\PartyMember;
use App\Party\Interface\Controller\PartyMemberController;
use App\Party\Interface\Request\TransferHostRequest;
use App\Party\Interface\Request\UpdatePartyMemberRequest;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Party\Domain\Model\SyncedPartySession;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

final class PartyMemberControllerTest extends TestCase
{
    public function testUpdateUsesTheResolvedPrincipalForMemberOwnership(): void
    {
        $userId = new Uuid();
        $session = SyncedPartySession::create($userId, new Uuid(), null);
        $sessionId = $session->getId();
        $sessions = $this->createStub(PartySessionPortInterface::class);
        $sessions->method('findByPublicIdentifier')->willReturn($session);
        $user = new SecurityUser($userId->toString(), 'member@baander.app', '');
        $member = PartyMember::create($userId, $sessionId);
        $members = $this->createMock(PartyMemberPortInterface::class);
        $members->expects($this->once())->method('findByUserAndSession')
            ->with(
                $this->callback(static fn (Uuid $id): bool => $id->equals($userId)),
                $this->callback(static fn (Uuid $id): bool => $id->equals($sessionId)),
            )
            ->willReturn($member);
        $members->expects($this->once())->method('save')->with($member);
        $controller = new PartyMemberController(
            $this->security($user),
            $this->createStub(MessageBusInterface::class),
            $sessions,
            $this->identities(),
            $members,
        );

        $response = $controller->updateMe($session->getPublicId()->toString(), new UpdatePartyMemberRequest('stereo', 'eng'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('stereo', $member->getAudioProfileId());
        self::assertSame('eng', $member->getSubtitleTrackId());
    }

    public function testTransferDispatchesTheResolvedCurrentHostIdentity(): void
    {
        $userId = new Uuid();
        $session = SyncedPartySession::create($userId, new Uuid(), null);
        $sessionId = $session->getId();
        $sessions = $this->createStub(PartySessionPortInterface::class);
        $sessions->method('findByPublicIdentifier')->willReturn($session);
        $newHost = new Uuid();
        $user = new SecurityUser($userId->toString(), 'member@baander.app', '');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->with($this->callback(static fn (TransferHostCommand $command): bool =>
                $command->getSessionId()->equals($sessionId)
                && $command->getCurrentHostUserId()->equals($userId)
                && $command->getNewHostUserId()->equals($newHost)
            ))
            ->willReturnCallback(static fn (object $command): Envelope => new Envelope($command));
        $controller = new PartyMemberController(
            $this->security($user),
            $bus,
            $sessions,
            $this->identities($newHost),
            $this->createStub(PartyMemberPortInterface::class),
        );

        $response = $controller->transferHost($session->getPublicId()->toString(), new TransferHostRequest((new PublicId())->toString()));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAnonymousActionsPreserveUnauthorizedResponseWithoutSideEffects(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $members = $this->createMock(PartyMemberPortInterface::class);
        $members->expects($this->never())->method('findByUserAndSession');
        $members->expects($this->never())->method('save');
        $controller = new PartyMemberController($this->security(null), $bus, $this->createStub(PartySessionPortInterface::class), $this->identities(), $members);
        $sessionId = (new Uuid())->toString();

        $update = $controller->updateMe($sessionId, new UpdatePartyMemberRequest());
        $transfer = $controller->transferHost($sessionId, new TransferHostRequest());

        self::assertSame(401, $update->getStatusCode());
        self::assertSame(401, $transfer->getStatusCode());
    }

    public function testPrincipalWithoutTheApplicationIdentityCannotMutateMembership(): void
    {
        $user = $this->createStub(UserInterface::class);
        $user->method('getRoles')->willReturn([]);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $members = $this->createMock(PartyMemberPortInterface::class);
        $members->expects($this->never())->method('findByUserAndSession');
        $members->expects($this->never())->method('save');
        $controller = new PartyMemberController(
            $this->security($user),
            $bus,
            $this->createStub(PartySessionPortInterface::class),
            $this->identities(),
            $members,
        );
        $sessionId = (new Uuid())->toString();

        $update = $controller->updateMe($sessionId, new UpdatePartyMemberRequest());
        $transfer = $controller->transferHost($sessionId, new TransferHostRequest());

        self::assertSame(401, $update->getStatusCode());
        self::assertSame(401, $transfer->getStatusCode());
    }

    private function security(?UserInterface $user): Security
    {
        $storage = new TokenStorage();
        if ($user !== null) {
            $storage->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));
        }
        $container = new Container();
        $container->set('security.token_storage', $storage);

        return new Security($container);
    }
    private function identities(?Uuid $target = null): \App\Party\Application\Port\PartyPublicIdentityPortInterface
    {
        $identities = $this->createStub(\App\Party\Application\Port\PartyPublicIdentityPortInterface::class);
        $identities->method('userId')->willReturn($target ?? new Uuid());
        $identities->method('member')->willReturnCallback(static fn (PartyMember $member) => new \App\Party\Application\DTO\PartyMemberView($member, new PublicId(), new PublicId()));

        return $identities;
    }

}
