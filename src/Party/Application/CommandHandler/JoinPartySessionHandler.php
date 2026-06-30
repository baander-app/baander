<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\JoinPartySessionCommand;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberJoined;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\ValueObject\MemberRole;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class JoinPartySessionHandler
{
    public function __construct(
        private readonly PartySessionPortInterface $sessionPort,
        private readonly PartyMemberPortInterface $memberPort,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(JoinPartySessionCommand $command): PartyMember
    {
        $session = $this->sessionPort->findByUuid($command->getSessionId());
        if ($session === null || !$session->isActive()) {
            throw new RuntimeException('Party session not found or inactive.');
        }

        $memberCount = $this->memberPort->countBySession($command->getSessionId());
        if ($memberCount >= $session->getMaxMembers()) {
            throw new RuntimeException('Party session is full.');
        }

        $existing = $this->memberPort->findByUserAndSession($command->getUserId(), $command->getSessionId());
        if ($existing !== null) {
            $existing->reconnect();
            $this->memberPort->save($existing);

            return $existing;
        }

        $member = $this->memberPort->addMember($command->getUserId(), $command->getSessionId());

        $this->eventDispatcher->dispatch(new MemberJoined(
            sessionId: $command->getSessionId(),
            userId: $command->getUserId(),
            role: MemberRole::Member->value,
        ));

        return $member;
    }
}
