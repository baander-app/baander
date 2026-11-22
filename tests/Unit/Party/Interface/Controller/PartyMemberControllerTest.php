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
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Party\Domain\Model\SyncedPartySession;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Translation\Translator;

final class PartyMemberControllerTest extends TestCase
{
    public function testMemberListServesAMember(): void
    {
        $userId = new Uuid();
        $session = SyncedPartySession::create(new Uuid(), new Uuid(), null);
        $host = PartyMember::create($session->getHostUserId(), $session->getId());
        $member = PartyMember::create($userId, $session->getId());
        $sessions = $this->createStub(PartySessionPortInterface::class);
        $sessions->method('findByPublicIdentifier')->willReturn($session);
        $members = $this->createStub(PartyMemberPortInterface::class);
        $members->method('findByUserAndSession')->willReturnCallback(
            static fn (Uuid $caller, Uuid $sessionId): ?PartyMember => $caller->equals($userId) && $sessionId->equals($session->getId()) ? $member : null,
        );
        $members->method('findBySession')->willReturn([$host, $member]);
        $controller = new PartyMemberController(
            $this->security(new SecurityUser($userId->toString(), 'member@baander.app', '')),
            $this->createStub(MessageBusInterface::class),
            $sessions,
            $this->identities(),
            $members,
        );

        $response = $controller->index($session->getPublicId()->toString());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [$host->getPublicId()->toString(), $member->getPublicId()->toString()],
            array_column($data, 'publicId'),
        );
    }

    public function testMemberListIsHiddenFromACallerWithoutAMemberRow(): void
    {
        $session = SyncedPartySession::create(new Uuid(), new Uuid(), null);
        $sessions = $this->createStub(PartySessionPortInterface::class);
        $sessions->method('findByPublicIdentifier')->willReturnCallback(
            static fn (string $publicId): ?SyncedPartySession => $publicId === $session->getPublicId()->toString() ? $session : null,
        );
        $members = $this->createMock(PartyMemberPortInterface::class);
        $members->method('findByUserAndSession')->willReturn(null);
        $members->expects($this->never())->method('findBySession');
        $controller = new PartyMemberController(
            $this->security(new SecurityUser((new Uuid())->toString(), 'outsider@baander.app', '')),
            $this->createStub(MessageBusInterface::class),
            $sessions,
            $this->identities(),
            $members,
        );
        $controller->setTranslator(new Translator('en'));

        $hidden = $controller->index($session->getPublicId()->toString());
        $missing = $controller->index((new PublicId())->toString());

        self::assertSame(404, $hidden->getStatusCode());
        // The answer does not reveal that the session exists.
        self::assertSame($missing->getContent(), $hidden->getContent());
    }

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

    public function testTransferDispatchesTheCallerAndTheNamedTargetWithoutLookingTheTargetUp(): void
    {
        $userId = new Uuid();
        $session = SyncedPartySession::create($userId, new Uuid(), null);
        $sessionId = $session->getId();
        $sessions = $this->createStub(PartySessionPortInterface::class);
        $sessions->method('findByPublicIdentifier')->willReturn($session);
        $target = new PublicId();
        $user = new SecurityUser($userId->toString(), 'member@baander.app', '');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->with($this->callback(static fn (TransferHostCommand $command): bool =>
                $command->getSessionId()->equals($sessionId)
                && $command->getCurrentHostUserId()->equals($userId)
                && $command->getNewHostPublicId()->equals($target)
            ))
            ->willReturnCallback(static fn (object $command): Envelope => new Envelope($command));
        // Nobody has the target's public ID. The handler says so, and only to the host, so the
        // route dispatches without asking.
        $identities = $this->identities();
        $identities->method('userId')->willThrowException(new NotFoundException('User not found.'));
        $members = $this->createStub(PartyMemberPortInterface::class);
        $members->method('findByUserAndSession')->willReturn(PartyMember::create($userId, $sessionId));
        $controller = new PartyMemberController(
            $this->security($user),
            $bus,
            $sessions,
            $identities,
            $members,
        );

        $response = $controller->transferHost($session->getPublicId()->toString(), new TransferHostRequest($target->toString()));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTransferAnswersACallerWithoutAMemberRowAsForAPartyThatDoesNotExistWhoeverTheyName(): void
    {
        $session = SyncedPartySession::create(new Uuid(), new Uuid(), null);
        $sessions = $this->createStub(PartySessionPortInterface::class);
        $sessions->method('findByPublicIdentifier')->willReturnCallback(
            static fn (string $publicId): ?SyncedPartySession => $publicId === $session->getPublicId()->toString() ? $session : null,
        );
        $members = $this->createStub(PartyMemberPortInterface::class);
        $members->method('findByUserAndSession')->willReturn(null);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        // Nobody has the named public ID, which the caller must not learn.
        $identities = $this->identities();
        $identities->method('userId')->willThrowException(new NotFoundException('User not found.'));
        $controller = new PartyMemberController(
            $this->security(new SecurityUser((new Uuid())->toString(), 'outsider@baander.app', '')),
            $bus,
            $sessions,
            $identities,
            $members,
        );
        $controller->setTranslator(new Translator('en'));
        $request = new TransferHostRequest((new PublicId())->toString());

        $hidden = $controller->transferHost($session->getPublicId()->toString(), $request);
        $missing = $controller->transferHost((new PublicId())->toString(), $request);

        self::assertSame(404, $hidden->getStatusCode());
        // The answer does not reveal that the session exists.
        self::assertSame($missing->getContent(), $hidden->getContent());
    }

    public function testAnonymousActionsPreserveUnauthorizedResponseWithoutSideEffects(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $members = $this->createMock(PartyMemberPortInterface::class);
        $members->expects($this->never())->method('findByUserAndSession');
        $members->expects($this->never())->method('save');
        $controller = new PartyMemberController($this->security(null), $bus, $this->createStub(PartySessionPortInterface::class), $this->identities(), $members);
        $controller->setTranslator(new Translator('en'));
        $sessionId = (new Uuid())->toString();

        $list = $controller->index($sessionId);
        $update = $controller->updateMe($sessionId, new UpdatePartyMemberRequest());
        $transfer = $controller->transferHost($sessionId, new TransferHostRequest());

        self::assertSame(401, $list->getStatusCode());
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
        $controller->setTranslator(new Translator('en'));
        $sessionId = (new Uuid())->toString();

        $list = $controller->index($sessionId);
        $update = $controller->updateMe($sessionId, new UpdatePartyMemberRequest());
        $transfer = $controller->transferHost($sessionId, new TransferHostRequest());

        self::assertSame(401, $list->getStatusCode());
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
    private function identities(): \App\Party\Application\Port\PartyPublicIdentityPortInterface&Stub
    {
        $identities = $this->createStub(\App\Party\Application\Port\PartyPublicIdentityPortInterface::class);
        $identities->method('member')->willReturnCallback(static fn (PartyMember $member) => new \App\Party\Application\DTO\PartyMemberView($member, new PublicId(), new PublicId()));

        return $identities;
    }

}
