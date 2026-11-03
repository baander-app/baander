<?php

declare(strict_types=1);

namespace App\Transcode\Application\Service;

use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\CopyPresentation;
use App\Transcode\Application\DTO\CopyPresentationInput;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\MediaOperation;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\CopyPresentationPortInterface;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use App\Transcode\Domain\ValueObject\SessionPriority;
use App\Transcode\Domain\ValueObject\TranscodeStatus;

final readonly class CopyPresentationService implements CopyPresentationPortInterface
{
    public function __construct(
        private MediaExecutionPolicy $policy,
        private TranscodeJobPortInterface $jobs,
        private TranscodeSessionPortInterface $sessions,
        private TranscodeStoragePortInterface $storage,
        private TranscodeExecutionOwnershipPort $ownership,
        private TransactionPortInterface $transaction,
        private SleeperInterface $sleeper,
    ) {
    }

    public function start(CopyPresentationInput $input): CopyPresentation
    {
        $owner = $input->owner;
        $video = $input->video;
        $width = $input->width;
        $height = $input->height;
        $requestId = $input->requestId;
        $selection = new PresentationSelection('copy', $input->sourceHash, $input->videoStreamIndex, $input->audioStreamIndex, $input->container);
        Uuid::fromString($requestId);
        $this->policy->assertAllowed(MediaOperation::VideoStreamCopy);
        $profile = AudioProfile::streamingStereo();
        $session = $this->transaction->run(function () use ($owner, $video, $selection, $width, $height, $requestId, $profile): TranscodeSession {
            $this->ownership->lockAdmission($video);
            $job = $this->jobs->getOrCreateCopyJob($video, $this->storage->resolveJobDirectory($video, 'copy'), $owner, $selection, $width, $height, $requestId);
            $runtime = $this->ownership->find($job->getId());
            if ($runtime !== null) {
                $this->ownership->lockSession($runtime->sessionId);
                $runtime = $this->ownership->find($job->getId());
            }
            if ($runtime !== null && $runtime->requestId === $requestId && in_array($runtime->outcome, ['pending', 'accepted', 'ready', 'complete'], true)) {
                $existing = $this->sessions->findByUuid($runtime->sessionId);
                if ($existing !== null && $existing->getUserId()->equals($owner)) {
                    return $existing;
                }
            }
            if (in_array($job->getStatus(), [TranscodeStatus::Failed, TranscodeStatus::Cancelled], true)) {
                $job->retry();
            }
            $job->attachSession();
            $this->jobs->save($job);
            $session = $this->sessions->createSession($owner, $job->getId(), $video, $profile, SessionPriority::Normal);
            $this->ownership->admit($job->getId(), $session->getId(), $requestId, new DesiredTranscodeState($profile, presentationSelection: $selection));
            return $session;
        });
        for ($poll = 0; $poll < 50; ++$poll) {
            $runtime = $this->ownership->find($session->getJobId());
            if ($runtime !== null && $runtime->sessionId->equals($session->getId())) {
                if ($runtime->outcome === 'complete' || ($runtime->ownershipLive && in_array($runtime->outcome, ['accepted', 'ready'], true))) {
                    $job = $this->jobs->findByUuid($session->getJobId()) ?? throw new TranscodeStartupUnavailableException();
                    return new CopyPresentation($job->getPublicId(), $session->getPublicId(), $requestId);
                }
                if (in_array($runtime->outcome, ['failed', 'refused', 'cancelled'], true)) {
                    throw new TranscodeStartupUnavailableException();
                }
            }
            $this->sleeper->sleep(0.1);
        }
        $expired = $this->ownership->expireAdmission($session->getJobId(), $session->getId(), $requestId);
        if (!$expired) {
            $runtime = $this->ownership->find($session->getJobId());
            if ($runtime !== null && $runtime->sessionId->equals($session->getId()) && $runtime->requestId === $requestId
                && ($runtime->outcome === 'complete' || ($runtime->ownershipLive && in_array($runtime->outcome, ['accepted', 'ready'], true)))) {
                $job = $this->jobs->findByUuid($session->getJobId()) ?? throw new TranscodeStartupUnavailableException();
                return new CopyPresentation($job->getPublicId(), $session->getPublicId(), $requestId);
            }
        }
        throw new TranscodeStartupUnavailableException();
    }
}
