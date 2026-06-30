<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Event\TranscodeSessionAttached;
use App\Transcode\Domain\Model\TranscodeSession;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CreateTranscodeSessionHandler
{
    public function __construct(
        private readonly TranscodeJobPortInterface $jobPort,
        private readonly TranscodeSessionPortInterface $sessionPort,
        private readonly TranscodeStoragePortInterface $storagePort,
        private readonly EventDispatcherInterface $eventDispatcher,
    )
    {
    }

    #[AsMessageHandler]
    public function __invoke(CreateTranscodeSessionCommand $command): TranscodeSession
    {
        $outputDirectory = $this->storagePort->resolveJobDirectory(
            $command->getVideoId(),
            $command->getQualityTier(),
        );

        $job = $this->jobPort->getOrCreateJob(
            $command->getVideoId(),
            $command->getQualityTier(),
            $outputDirectory,
            $command->getAudioLanguages(),
        );

        // Set audio track languages on job if not already set
        if (empty($job->getAudioTrackLanguages()) && !empty($command->getAudioLanguages())) {
            $job->setAudioTrackLanguages($command->getAudioLanguages());
        }

        $job->attachSession();
        $this->jobPort->save($job);

        $session = $this->sessionPort->createSession(
            $command->getUserId(),
            $job->getId(),
            $command->getVideoId(),
            $command->getAudioProfile(),
            $command->getPriority(),
            $command->getAudioLanguages(),
        );

        $this->eventDispatcher->dispatch(new TranscodeSessionAttached(
            sessionId: $session->getId(),
            jobId: $job->getId(),
            userId: $command->getUserId(),
            qualityTier: $job->getQualityTierName(),
        ));

        return $session;
    }
}
