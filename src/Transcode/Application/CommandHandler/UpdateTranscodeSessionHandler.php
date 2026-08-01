<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\UpdateTranscodeSessionCommand;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\Exception\SessionNotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UpdateTranscodeSessionHandler
{
    public function __construct(
        private readonly TranscodeSessionPortInterface $sessionPort,
    ) {}

    public function __invoke(UpdateTranscodeSessionCommand $command): void
    {
        $session = $this->sessionPort->findByUuid($command->sessionId);
        if ($session === null) {
            throw SessionNotFoundException::forId($command->sessionId);
        }
        if ($command->audioProfile !== null) {
            $session->updateAudioProfile($command->audioProfile);
        }
        $this->sessionPort->save($session);
    }
}
