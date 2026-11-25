<?php

declare(strict_types=1);

namespace App\Discovery\Application\CommandHandler;

use App\Discovery\Application\Command\CompletePairingCommand;
use App\Discovery\Application\Port\DiscoveryPortInterface;
use App\Discovery\Domain\Event\PairingCompleted;
use App\Discovery\Domain\Model\PairingSession;
use App\Discovery\Domain\ValueObject\PairingCode;
use App\Shared\Application\Exception\NotFoundException;
use InvalidArgumentException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CompletePairingHandler
{
    public function __construct(
        private readonly DiscoveryPortInterface $discoveryPort,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @throws NotFoundException for a code that matches no session, has expired or belongs to
     *                           another server, alike, so the refusal does not tell whether the
     *                           code is live for some server
     */
    #[AsMessageHandler]
    public function __invoke(CompletePairingCommand $command): PairingSession
    {
        if (!self::isPairingCode($command->getPairingCode())) {
            throw self::refusal();
        }

        $session = $this->discoveryPort->findByPairingCode($command->getPairingCode());
        if ($session === null) {
            throw self::refusal();
        }

        if ($session->isExpired()) {
            throw self::refusal();
        }

        if (!$session->getServerPublicId()->equals($command->getServerPublicId())) {
            throw self::refusal();
        }

        if ($session->isCompleted()) {
            return $session;
        }

        $this->discoveryPort->completePairing($session);

        $this->eventDispatcher->dispatch(new PairingCompleted(
            pairingId: $session->getId(),
            pairingPublicId: $session->getPublicId(),
            serverId: $session->getServerId(),
            method: $session->getMethod(),
        ));

        return $session;
    }

    private static function isPairingCode(string $code): bool
    {
        try {
            PairingCode::fromString($code);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private static function refusal(): NotFoundException
    {
        return new NotFoundException('Pairing code not found or expired.');
    }
}
