<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Party\Application\Command\InvitePartyMemberCommand;
use App\Party\Application\Command\RevokePartyInvitationCommand;
use App\Party\Application\DTO\PartyInvitationView;
use App\Party\Application\Port\PartyInvitationPortInterface;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Model\PartyInvitation;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Interface\Controller\PartyInvitationController;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Translation\Translator;

/**
 * The party in these tests has a host and one member who is not the host. Its ports answer for
 * that party only, so any other public ID is a party that does not exist.
 */
final class PartyInvitationControllerTest extends TestCase
{
    private SyncedPartySession $session;
    private Uuid $host;
    private Uuid $member;
    private PartySessionPortInterface&Stub $sessions;
    private PartyMemberPortInterface&Stub $members;

    protected function setUp(): void
    {
        $this->host = new Uuid();
        $this->member = new Uuid();
        $this->session = SyncedPartySession::create($this->host, new Uuid(), null);

        $this->sessions = $this->createStub(PartySessionPortInterface::class);
        $this->sessions->method('findByPublicIdentifier')->willReturnCallback(
            fn (string $publicId): ?SyncedPartySession => $publicId === $this->party() ? $this->session : null,
        );
        $this->members = $this->createStub(PartyMemberPortInterface::class);
        $this->members->method('findByUserAndSession')->willReturnCallback(
            fn (Uuid $caller, Uuid $sessionId): ?PartyMember => $sessionId->equals($this->session->getId())
                && ($caller->equals($this->host) || $caller->equals($this->member))
                    ? PartyMember::create($caller, $sessionId)
                    : null,
        );
    }

    public function testACallerWhoIsNotSignedInIsUnauthorizedAndNothingIsDispatched(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $invitations = $this->createMock(PartyInvitationPortInterface::class);
        $invitations->expects($this->never())->method('findBySession');
        $controller = $this->controller(null, $bus, $invitations);

        foreach ($this->answers($controller, $this->party()) as $route => $answer) {
            self::assertSame(401, $answer->getStatusCode(), $route);
        }
    }

    public function testACallerWithoutAMemberRowIsAnsweredAsForAPartyThatDoesNotExist(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $invitations = $this->createMock(PartyInvitationPortInterface::class);
        $invitations->expects($this->never())->method('findBySession');
        $controller = $this->controller(new Uuid(), $bus, $invitations);

        $hidden = $this->answers($controller, $this->party());
        $missing = $this->answers($controller, (new PublicId())->toString());

        foreach ($hidden as $route => $answer) {
            self::assertSame(404, $answer->getStatusCode(), $route);
            // The answer does not reveal that the party exists.
            self::assertSame($missing[$route]->getContent(), $answer->getContent(), $route);
        }
    }

    public function testAnInviteDispatchesTheCallerAndTheNamedUserWithoutLookingTheUserUp(): void
    {
        $target = new PublicId();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->with($this->callback(fn (InvitePartyMemberCommand $command): bool => $command->getSessionId()->equals($this->session->getId())
                && $command->getHostUserId()->equals($this->member)
                && $command->getUserPublicId()->equals($target)))
            ->willReturnCallback(static fn (object $command): Envelope => new Envelope($command));

        // The caller is a member who is not the host and nobody has the target's public ID. The
        // handler decides both under the party's lock, so the route dispatches without asking.
        $response = $this->controller($this->member, $bus, identities: $this->identitiesThatKnowNobody())
            ->invite($this->party(), $target->toString());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testARevokeDispatchesTheCallerAndTheNamedUserWithoutLookingTheUserUp(): void
    {
        $target = new PublicId();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->with($this->callback(fn (RevokePartyInvitationCommand $command): bool => $command->getSessionId()->equals($this->session->getId())
                && $command->getHostUserId()->equals($this->member)
                && $command->getUserPublicId()->equals($target)))
            ->willReturnCallback(static fn (object $command): Envelope => new Envelope($command));

        $response = $this->controller($this->member, $bus, identities: $this->identitiesThatKnowNobody())
            ->revoke($this->party(), $target->toString());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testTheListIsForbiddenToAMemberWhoIsNotTheHost(): void
    {
        $invitations = $this->createMock(PartyInvitationPortInterface::class);
        $invitations->expects($this->never())->method('findBySession');
        $controller = $this->controller($this->member, invitations: $invitations);

        $this->expectException(AccessDeniedException::class);

        $controller->index($this->party());
    }

    public function testTheHostListsTheInvitationsWithTheirUsersPublicIds(): void
    {
        $stored = [
            PartyInvitation::issue($this->host, $this->session->getId()),
            PartyInvitation::issue($this->member, $this->session->getId()),
        ];
        $publicIds = [new PublicId(), new PublicId()];
        $invitations = $this->createStub(PartyInvitationPortInterface::class);
        $invitations->method('findBySession')->willReturnCallback(
            fn (Uuid $sessionId): array => $sessionId->equals($this->session->getId()) ? $stored : [],
        );
        $identities = $this->createStub(PartyPublicIdentityPortInterface::class);
        $identities->method('invitations')->willReturnCallback(
            static fn (array $listed): array => array_map(
                static fn (PartyInvitation $invitation, PublicId $publicId): PartyInvitationView => new PartyInvitationView($invitation, $publicId),
                $listed,
                $publicIds,
            ),
        );

        $response = $this->controller($this->host, invitations: $invitations, identities: $identities)->index($this->party());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['data' => [
                ['userId' => $publicIds[0]->toString(), 'invitedAt' => $stored[0]->getCreatedAt()->format(\DateTimeInterface::ATOM)],
                ['userId' => $publicIds[1]->toString(), 'invitedAt' => $stored[1]->getCreatedAt()->format(\DateTimeInterface::ATOM)],
            ]],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, JsonResponse> the answer of each invitation route, by route */
    private function answers(PartyInvitationController $controller, string $party): array
    {
        $user = (new PublicId())->toString();

        return [
            'list' => $controller->index($party),
            'invite' => $controller->invite($party, $user),
            'revoke' => $controller->revoke($party, $user),
        ];
    }

    private function controller(
        ?Uuid $caller,
        ?MessageBusInterface $bus = null,
        ?PartyInvitationPortInterface $invitations = null,
        ?PartyPublicIdentityPortInterface $identities = null,
    ): PartyInvitationController {
        $controller = new PartyInvitationController(
            $this->security($caller === null ? null : new SecurityUser($caller->toString(), 'caller@baander.app', '')),
            $bus ?? $this->createStub(MessageBusInterface::class),
            $this->sessions,
            $this->members,
            $invitations ?? $this->createStub(PartyInvitationPortInterface::class),
            $identities ?? $this->createStub(PartyPublicIdentityPortInterface::class),
        );
        $controller->setTranslator(new Translator('en'));

        return $controller;
    }

    private function identitiesThatKnowNobody(): PartyPublicIdentityPortInterface&Stub
    {
        $identities = $this->createStub(PartyPublicIdentityPortInterface::class);
        $identities->method('userId')->willThrowException(new NotFoundException('User not found.'));

        return $identities;
    }

    private function party(): string
    {
        return $this->session->getPublicId()->toString();
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
}
