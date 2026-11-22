<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\LeavePartySessionCommand;
use App\Party\Application\Port\PartyMediaAccessPortInterface;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberLeft;
use App\Party\Domain\Event\PartySessionEnded;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Takes a member out of a party. When the host leaves, the earliest-joined remaining member
 * becomes the host; when nobody remains, the party ends. A member who owns the party's transcode
 * job takes it along: nobody left in the party steers their encoder.
 *
 * The leave runs under the party's row lock, so it decides on the host and the members as they
 * are stored once its turn comes. A user who holds no member row changes nothing. The events
 * leave after the commit: the live room drops the member's sockets on MemberLeft, and dropped
 * before the delete is visible, a socket joining meanwhile would stay in the room.
 */
final readonly class LeavePartySessionHandler
{
    public function __construct(
        private PartySessionPortInterface $sessionPort,
        private PartyMemberPortInterface $memberPort,
        private PartyMediaAccessPortInterface $mediaAccess,
        private TransactionPortInterface $transaction,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(LeavePartySessionCommand $command): void
    {
        $events = $this->transaction->run(
            fn (): array => $this->leave($command->getSessionId(), $command->getUserId()),
        );

        foreach ($events as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * @return list<MemberLeft|PartySessionEnded> what the leave changed: MemberLeft first, so the
     *                                            live room drops the member before it closes
     */
    private function leave(Uuid $sessionId, Uuid $userId): array
    {
        $session = $this->sessionPort->lockByUuid($sessionId);
        if ($session === null || !$this->memberPort->removeMember($userId, $sessionId)) {
            return [];
        }

        $this->releaseJobOwnedBy($session, $userId);

        $left = new MemberLeft(sessionId: $sessionId, userId: $userId);
        if (!$session->getHostUserId()->equals($userId)) {
            return [$left];
        }

        $newHost = $this->memberPort->findBySession($sessionId) |> $this->earliestJoined(...);
        if ($newHost === null) {
            $session->endSession();
            $this->sessionPort->save($session);

            return [$left, new PartySessionEnded(sessionId: $sessionId, hostUserId: $userId)];
        }

        // On the session as well as the member row, so the new host steers playback and the
        // leaver no longer does.
        $session->transferHost($newHost->getUserId());
        $this->sessionPort->save($session);
        $newHost->promoteToHost();
        $this->memberPort->save($newHost);

        return [$left];
    }

    private function releaseJobOwnedBy(SyncedPartySession $session, Uuid $userId): void
    {
        $jobId = $session->getTranscodeJobId();
        if ($jobId === null || !$this->mediaAccess->ownsTranscodeJob($userId, $jobId)) {
            return;
        }

        $session->releaseTranscodeJob();
        $this->sessionPort->save($session);
    }

    /** @param PartyMember[] $members */
    private function earliestJoined(array $members): ?PartyMember
    {
        usort($members, static fn (PartyMember $a, PartyMember $b): int => $a->getJoinedAt() <=> $b->getJoinedAt());

        return array_first($members);
    }
}
