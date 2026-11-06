<?php

declare(strict_types=1);

namespace App\Session\Application\Service;

use App\Session\Application\Port\PlaybackCoordinationInterface;
use App\Session\Application\DTO\PlaybackRejectionReason;
use App\Session\Application\Port\PlaybackStoreInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Session\Domain\Model\Playback\ObservedPlaybackState;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\PlaybackTransfer;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use InvalidArgumentException;

final readonly class PlaybackCoordinator implements PlaybackCoordinationInterface
{
    public function __construct(
        private PlaybackStoreInterface $store,
        private TransactionPortInterface $transactions,
        private PlaybackMediaAccessInterface $mediaAccess,
    ) {}

    public function execute(Uuid $userId, PlaybackOperation $operation, #[\SensitiveParameter] array $input, ?string $endpointCapability = null): array
    {
        try {
            return $this->coordinate($userId, $operation, $input, $endpointCapability);
        } catch (\DomainException $error) {
            throw new ConflictException($error->getMessage(), previous: $error);
        } catch (InvalidArgumentException $error) {
            throw new InvalidInputException($error->getMessage(), previous: $error);
        }
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function coordinate(Uuid $userId, PlaybackOperation $operation, #[\SensitiveParameter] array $input, ?string $endpointCapability): array
    {
        $owner = $userId->toString();
        $incarnationId = isset($input['incarnationId']) ? $this->uuid($input, 'incarnationId') : null;
        $endpoint = null;
        if (in_array($operation, [PlaybackOperation::Acknowledge, PlaybackOperation::Observe], true)
            || (in_array($operation, [PlaybackOperation::Snapshot, PlaybackOperation::Control], true) && $incarnationId !== null)) {
            if ($incarnationId === null || $endpointCapability === null || $endpointCapability === '') {
                throw new ConflictException('An endpoint capability is required.');
            }
            $endpoint = $this->store->endpoint($owner, $incarnationId, $endpointCapability);
        }
        $registration = null;
        $result = $this->transactions->run(function () use ($userId, $owner, $operation, $input, $endpoint, $incarnationId, &$registration): array {
            $session = $this->store->lockSession($owner);
            if ($operation === PlaybackOperation::RecoverLocal) {
                return $this->recoverLocal($session, $input);
            }
            $before = serialize($session->getState());
            $transfer = $this->store->currentTransfer($session->getId()->toString());
            if ($transfer !== null && new DateTimeImmutable($transfer['expires_at']) <= new DateTimeImmutable()) {
                $model = $this->model($transfer);
                $model->expire();
                $this->store->cancelCommands($transfer['id']);
                $session->advanceRevision();
                $this->persistTransfer($session, $transfer, $model);
                if ($model->state() === 'uncertain') {
                    $session->retainUncertainRunner();
                }
                $transfer = $this->store->currentTransfer($session->getId()->toString());
            }
            $this->expireControl($session);
            switch ($operation) {
                case PlaybackOperation::Endpoint:
                    $input['deviceId'] = $this->uuid($input, 'deviceId');
                    $input['endpointId'] = $this->uuid($input, 'endpointId');
                    $registration = $this->store->register($owner, $input);
                    break;
                case PlaybackOperation::Transfer:
                    $requestId = $this->uuid($input, 'requestId');
                    if ($this->replayRequest($session, $requestId, $operation, $input)) {
                        break;
                    }
                    if ($this->store->hasPendingControl($session->getId()->toString())) {
                        throw new ConflictException('A runner control is awaiting acknowledgement.');
                    }
                    $this->expectRevision($session, $input);
                    if ($transfer !== null) {
                        throw new ConflictException('Another playback transfer is still being reconciled.');
                    }
                    $target = $this->store->endpoint($owner, $this->uuid($input, 'targetIncarnationId'));
                    if (!$target['available']) {
                        throw new ConflictException('The destination is unavailable.');
                    }
                    $media = $session->media();
                    if ($media !== null) {
                        if (!in_array($media->kind, $target['capabilities'], true)) {
                            throw new ConflictException('The destination cannot play this media kind.');
                        }
                        $this->assertPlayable($userId, $media);
                    }

                    $sourceId = $session->activeIncarnationId()?->toString();
                    if ($sourceId !== null) {
                        $source = $this->store->endpoint($owner, $sourceId);
                        if ($source['retired_at'] !== null && $source['observed_state'] === 'stopped') {
                            $sourceId = null;
                        }
                    }
                    $transfer = ['id' => (new Uuid())->toString(), 'session_id' => $session->getId()->toString(), 'user_id' => $owner,
                        'request_id' => $requestId, 'request_digest' => $this->requestDigest($operation, $input), 'source_incarnation_id' => $sourceId,
                        'target_incarnation_id' => $target['id'], 'desired_state' => $session->desiredState()->value];
                    if ($session->activeIncarnationId()?->toString() === $target['id']) {
                        $transfer += ['state' => 'committed', 'position' => $session->getPosition(), 'revision' => $session->revision()];
                        $this->store->saveTransfer($transfer);
                        break;
                    }
                    $session->selectDestination(Uuid::fromString($target['destination_id']));
                    $this->persistTransfer($session, $transfer, PlaybackTransfer::begin($transfer['id'], $transfer['source_incarnation_id'], $target['id'], $session->desiredState()->value, (float) $session->getPosition()));
                    break;
                case PlaybackOperation::Acknowledge:
                    $this->acknowledge($session, $input, $endpoint, $transfer);
                    break;
                case PlaybackOperation::Observe:
                    $state = $input['observedState'] ?? null;
                    if (!in_array($state, ['playing', 'paused', 'stopped', 'unknown'], true)) {
                        throw new InvalidArgumentException('Invalid observed playback state.');
                    }
                    $position = $this->position($input);
                    $observedVolume = array_key_exists('volume', $input) ? $this->volume($input) : null;
                    if ($observedVolume !== null && !in_array('volume', $endpoint['capabilities'], true)) {
                        throw new ConflictException('The destination does not support volume control.');
                    }
                    $this->store->observe($endpoint['id'], $state, $position, $observedVolume);
                    if ($session->activeIncarnationId()?->toString() === $endpoint['id'] && $transfer === null && !$this->store->hasPendingControl($session->getId()->toString())) {
                        $this->expectRevision($session, $input);
                        if ($state === 'stopped') {
                            // The runner's own stop report is the proof its silence needs: the session
                            // continues without an executor, so the same browser can start again after
                            // an outage that outlived its registration.
                            $session->releaseStoppedRunner($position);
                        } else {
                            $session->observePlayback(ObservedPlaybackState::from($state), $position);
                        }
                    }
                    break;
                case PlaybackOperation::Control:
                    $requestId = $this->uuid($input, 'requestId');
                    if ($this->replayRequest($session, $requestId, $operation, $input)) {
                        break;
                    }
                    if ($transfer !== null) {
                        throw new ConflictException('Playback controls wait for transfer reconciliation.');
                    }
                    $this->expectRevision($session, $input);
                    $action = $input['action'] ?? null;
                    if (!in_array($action, ['play', 'pause', 'seek', 'load', 'stop', 'volume', 'queue'], true)) {
                        throw new InvalidArgumentException('Invalid playback action.');
                    }
                    if ($this->store->hasPendingControl($session->getId()->toString())) {
                        if ($action !== 'stop') {
                            throw new ConflictException('A runner control is awaiting acknowledgement.');
                        }
                        $this->store->supersedeControls($session->getId()->toString());
                    }
                    $previousQueue = $session->getQueue();
                    $session->control(
                        $action,
                        isset($input['media']) ? $this->media($input['media']) : null,
                        $input['queue'] ?? null,
                        $input['currentTrackIndex'] ?? null,
                        array_key_exists('position', $input) ? $this->position($input) : null,
                        isset($input['desiredState']) ? (PlaybackState::tryFrom($input['desiredState']) ?? throw new InvalidArgumentException('Invalid desired state.')) : null,
                        array_key_exists('volume', $input) ? $this->volume($input) : null,
                    );
                    if (in_array($action, ['play', 'load', 'seek'], true) || isset($input['media']) || isset($input['queue'])) {
                        if ($session->media() !== null) {
                            $this->assertPlayable($userId, $session->media());
                        }
                        if ($session->getQueue() !== $previousQueue) {
                            foreach ($session->getQueue() as $queuedId) {
                                $this->assertPlayable($userId, new PlaybackMedia('song', PublicId::fromString($queuedId)));
                            }
                        }
                    }
                    if ($session->activeIncarnationId()?->toString() !== null) {
                        $runner = $this->store->endpoint($owner, $session->activeIncarnationId()->toString());
                        if ($action === 'volume' && !in_array('volume', $runner['capabilities'], true)) {
                            throw new ConflictException('The destination does not support volume control.');
                        }
                        if (!$runner['available'] && $action !== 'stop') {
                            throw new ConflictException('The runner is unavailable; silence has not been confirmed.');
                        }
                        $this->enqueue(
                            $session,
                            $requestId,
                            $requestId,
                            $runner['id'],
                            $action,
                            $this->requestDigest($operation, $input),
                            $requestId,
                            $action === 'volume' ? ['volume' => $this->volume($input)] : [],
                        );
                    } else {
                        if ($action === 'volume') {
                            throw new ConflictException('No active destination supports volume control.');
                        }
                        $this->enqueue($session, $requestId, $requestId, null, $action, $this->requestDigest($operation, $input), $requestId);
                    }
                    break;
                case PlaybackOperation::Reconcile:
                    $this->expectRevision($session, $input);
                    if ($transfer === null || $transfer['state'] !== 'uncertain') {
                        throw new ConflictException('No uncertain transfer needs reconciliation.');
                    }
                    $this->store->cancelCommands($transfer['id']);
                    $session->advanceRevision();
                    $this->persistTransfer($session, $transfer, $this->model($transfer));
                    break;
                case PlaybackOperation::Snapshot:
                    if ($transfer === null && $session->activeIncarnationId() !== null
                        && $session->desiredState() === PlaybackState::Paused
                        && $session->observedState() === ObservedPlaybackState::Paused
                        && $this->store->retireConfirmedPausedBrowser($session)) {
                        $session->releaseConfirmedPausedRunner();
                    }
                    break;
                default:
                    throw new InvalidArgumentException('Unknown playback operation.');
            }
            if (serialize($session->getState()) !== $before) {
                $this->store->saveSession($session);
            }
            $snapshot = $this->store->snapshot($session, $incarnationId);
            if ($operation === PlaybackOperation::Control) {
                $command = $this->store->requestedCommand($session->getId()->toString(), $this->uuid($input, 'requestId'));
                if ($command !== null) {
                    $snapshot['requestResult'] = [
                        'requestId' => $command['request_id'],
                        'commandId' => $command['id'],
                        'outcome' => $command['outcome'],
                        'rejectionReason' => $command['rejection_reason'] ?? null,
                    ];
                }
            }
            if ($operation === PlaybackOperation::Transfer) {
                $requestedTransfer = $this->store->requestedTransfer($session->getId()->toString(), $this->uuid($input, 'requestId'));
                if ($requestedTransfer !== null) {
                    $snapshot['transferResult'] = [
                        'requestId' => $requestedTransfer['request_id'],
                        'attemptId' => $requestedTransfer['id'],
                        'state' => $requestedTransfer['state'],
                        'rejectionReason' => $requestedTransfer['rejection_reason'],
                    ];
                }
            }
            return $snapshot;
        });
        if ($registration !== null) {
            $result['endpoint'] = $registration;
        }
        return $result;
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function recoverLocal(ListeningSession $session, #[\SensitiveParameter] array $input): array
    {
        $incarnation = $this->uuid($input, 'incarnationId');
        $tab = $this->uuid($input, 'tabId');
        $credential = $input['recoveryCredential'] ?? null;
        if (!is_string($credential) || preg_match('/^[0-9a-f]{64}$/D', $credential) !== 1) {
            throw new InvalidArgumentException('A local recovery credential is required.');
        }
        $record = $this->store->localRecovery($session->getUserId()->toString(), $incarnation, $tab, $credential);
        if ($record['recovery_result'] !== null) {
            return $this->store->snapshot($session, null) + ['recoveryResult' => $record['recovery_result']];
        }
        $checkpoint = $input['checkpoint'] ?? null;
        if (!is_array($checkpoint) || !array_key_exists('media', $checkpoint)
            || !isset($checkpoint['revision'], $checkpoint['currentTrackIndex'])
            || !is_int($checkpoint['revision']) || $checkpoint['revision'] < 0
            || !is_int($checkpoint['currentTrackIndex']) || $checkpoint['currentTrackIndex'] < 0) {
            throw new InvalidArgumentException('Invalid local recovery checkpoint.');
        }
        $position = $this->position($checkpoint);
        $media = $checkpoint['media'] === null ? null : $this->media($checkpoint['media']);
        $transfer = $this->store->currentTransfer($session->getId()->toString());
        $source = $transfer !== null && $transfer['source_incarnation_id'] === $incarnation;
        $target = $transfer !== null && $transfer['target_incarnation_id'] === $incarnation;
        $active = $session->activeIncarnationId()?->toString() === $incarnation;
        $preserveTarget = $source && in_array($transfer['state'], ['starting', 'uncertain'], true);
        $mutate = !$preserveTarget && ($source || $target || ($transfer === null && $active));
        $this->store->supersedeIncarnationCommands($incarnation);
        if ($mutate) {
            $targetPreparing = $target && $transfer['state'] === 'preparing';
            if ($transfer !== null) {
                $this->store->cancelCommands($transfer['id']);
                $transfer['state'] = 'failed';
            }
            if (!$targetPreparing) {
                $sourceId = $target ? $transfer['source_incarnation_id'] : null;
                $sourceStopped = $sourceId === null || $this->store->sourceQuiesced($transfer['id'], $sourceId);
                if ($sourceId !== null && !$sourceStopped) {
                    $sourceEndpoint = $this->store->endpoint($session->getUserId()->toString(), $sourceId);
                    $sourceStopped = ($sourceEndpoint['recovery_result'] ?? null) !== null
                        && $sourceEndpoint['retired_at'] !== null && $sourceEndpoint['observed_state'] === 'stopped';
                }
                $session->recoverLocalRunner(
                    $media,
                    $checkpoint['currentTrackIndex'],
                    $checkpoint['revision'],
                    $position,
                    !$target || $sourceId === null,
                    $sourceStopped,
                );
                if ($target && $sourceId !== null) {
                    $session->restoreSelection(Uuid::fromString($this->store->endpoint($session->getUserId()->toString(), $sourceId)['destination_id']));
                    if (!$sourceStopped) {
                        $obligation = (new Uuid())->toString();
                        $this->enqueue($session, $obligation, $obligation, $sourceId, 'stop', hash('sha256', $obligation));
                    }
                }
            } else {
                $session->restoreSelection($active ? null : ($session->activeIncarnationId() === null ? null : Uuid::fromString($this->store->endpoint($session->getUserId()->toString(), $session->activeIncarnationId()->toString())['destination_id'])));
                $session->advanceRevision();
            }
            if ($transfer !== null) {
                $transfer['revision'] = $session->revision();
                $this->store->saveTransfer($transfer);
            }
            $this->store->saveSession($session);
        }
        $result = ['incarnationId' => $incarnation, 'outcome' => 'recovered', 'revision' => $session->revision()];
        $this->store->completeLocalRecovery($incarnation, $result);
        return $this->store->snapshot($session, null) + ['recoveryResult' => $result];
    }

    private function expireControl(ListeningSession $session): void
    {
        $command = $this->store->pendingControl($session->getId()->toString());
        if ($command === null || new DateTimeImmutable($command['expires_at']) > new DateTimeImmutable()) {
            return;
        }
        $this->store->cancelCommands($command['attempt_id']);
        if (in_array($command['type'], ['volume', 'queue'], true)) {
            $session->advanceRevision();
            return;
        }
        $preservePaused = $command['type'] === 'stop' && $session->desiredState() === PlaybackState::Paused;
        $session->retainUncertainRunner();
        if ($preservePaused) {
            $session->advanceRevision();
        } else {
            $session->control(action: 'stop', media: null, queue: null, index: null, position: null, desired: null);
        }
        $this->enqueue(
            $session,
            $command['attempt_id'],
            $command['request_id'],
            $command['incarnation_id'],
            'stop',
            $command['request_digest'],
        );
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed>|null $transfer
     */
    private function acknowledge(ListeningSession $session, array $input, array $endpoint, ?array $transfer): void
    {
        $command = $this->store->command($session->getUserId()->toString(), $this->uuid($input, 'commandId'));
        if ($command['incarnation_id'] !== $endpoint['id'] || $command['attempt_id'] !== $this->uuid($input, 'attemptId')
            || (int) $command['epoch'] !== ($input['epoch'] ?? null) || (int) $command['revision'] !== ($input['revision'] ?? null)) {
            throw new ConflictException('Stale command acknowledgement.');
        }
        $outcome = $input['outcome'] ?? null;
        if (!is_string($outcome)) {
            throw new InvalidArgumentException('Acknowledgement outcome is required.');
        }
        $rejectionReason = $input['rejectionReason'] ?? null;
        if ($rejectionReason !== null && ($outcome !== 'rejected' || !in_array($rejectionReason, PlaybackRejectionReason::VALUES, true))) {
            throw new InvalidArgumentException('Invalid playback rejection reason.');
        }
        if ($command['acknowledged_at'] !== null) {
            if ($command['outcome'] !== $outcome || ($command['rejection_reason'] ?? null) !== $rejectionReason) {
                throw new ConflictException('Command outcome has already been recorded.');
            }
            return;
        }
        if ((int) $session->revision() !== (int) $command['revision']) {
            throw new ConflictException('The session revision has advanced.');
        }
        if ($transfer !== null && $transfer['id'] === $command['attempt_id']) {
            $model = $this->model($transfer);
            if ($model->command() !== $command['type'] || $model->commandEndpoint() !== $endpoint['id']) {
                throw new ConflictException('Transfer command has been superseded.');
            }
            $model->acknowledge($outcome, isset($input['position']) ? $this->position($input) : null);
            $session->advanceRevision();
            if ($outcome === 'quiesced') {
                $session->confirmQuiescence($model->position());
            }
            if ($model->state() === 'committed') {
                $target = $this->store->endpoint($session->getUserId()->toString(), $transfer['target_incarnation_id']);
                $session->commitDestination(Uuid::fromString($transfer['target_incarnation_id']), Uuid::fromString($target['destination_id']), PlaybackState::from($transfer['desired_state']), $model->position());
                $this->store->observe($target['id'], $transfer['desired_state'], $model->position());
            }
            if ($model->state() === 'uncertain') {
                $session->retainUncertainRunner();
            }
            if ($model->state() === 'failed') {
                $session->restoreSelection($session->activeIncarnationId() === null ? null : Uuid::fromString($this->store->endpoint($session->getUserId()->toString(), $session->activeIncarnationId()->toString())['destination_id']));
            }
            $this->store->acknowledgeCommand($command['id'], $outcome, $rejectionReason);
            $this->persistTransfer($session, $transfer, $model);
            return;
        }
        $expected = match ($command['type']) {
            'play' => 'playing','pause' => 'paused','stop' => 'stopped','seek','load','volume','queue' => 'applied', default => null,
        };
        if ($outcome !== $expected && $outcome !== 'rejected') {
            throw new ConflictException('Unexpected command acknowledgement.');
        }
        if ($command['type'] === 'volume') {
            if ($outcome === 'applied') {
                $this->store->observeVolume($endpoint['id'], $this->volume($input));
            }
            $this->store->acknowledgeCommand($command['id'], $outcome, $rejectionReason);
            $session->advanceRevision();
            return;
        }
        $this->store->acknowledgeCommand($command['id'], $outcome, $rejectionReason);
        if ($command['type'] === 'stop' && $outcome === 'rejected' && $session->desiredState() === PlaybackState::Paused) {
            $session->retainUncertainRunner();
            $session->advanceRevision();
            $this->enqueue($session, $command['attempt_id'], $command['request_id'], $endpoint['id'], 'stop', $command['request_digest']);
            return;
        }
        $session->confirmControl($outcome === 'rejected', $command['type'] !== 'stop' && isset($input['position']) ? $this->position($input) : null);
        $this->store->observe($endpoint['id'], $command['type'] === 'stop' && $outcome === 'stopped' ? 'stopped' : $session->observedState()->value, $session->getPosition());
        $session->advanceRevision();
    }

    /** @param array<string,mixed> $transfer */
    private function persistTransfer(ListeningSession $session, array $transfer, PlaybackTransfer $model): void
    {
        $transfer['state'] = $model->state();
        $transfer['position'] = $model->position();
        $transfer['revision'] = $session->revision();
        $this->store->saveTransfer($transfer);
        if ($model->command() !== null) {
            $payload = ['position' => $model->position(), 'desiredState' => $model->desiredState];
            $this->enqueue($session, $transfer['id'], $transfer['request_id'], $model->commandEndpoint(), $model->command(), $transfer['request_digest'], null, $payload);
        }
    }

    /** @param array<string,mixed> $extra */
    private function enqueue(ListeningSession $session, string $attemptId, string $requestId, ?string $incarnationId, string $type, string $requestDigest, ?string $id = null, array $extra = []): void
    {
        $endpoint = $incarnationId === null ? null : $this->store->endpoint($session->getUserId()->toString(), $incarnationId);
        $this->store->insertCommand(['id' => $id ?? (new Uuid())->toString(), 'session_id' => $session->getId()->toString(), 'user_id' => $session->getUserId()->toString(),
            'attempt_id' => $attemptId, 'request_id' => $requestId, 'request_digest' => $requestDigest, 'incarnation_id' => $incarnationId, 'epoch' => $endpoint === null ? null : (int) $endpoint['epoch'],
            'revision' => $session->revision(), 'type' => $type,
            'payload' => $extra + ['media' => $session->media()?->toArray(), 'queue' => $session->getQueue(), 'currentTrackIndex' => (int) $session->getCurrentTrackIndex(),
                'position' => (float) $session->getPosition(), 'desiredState' => $session->desiredState()->value]]);
    }

    /** @param array<string,mixed> $transfer */
    private function model(array $transfer): PlaybackTransfer
    {
        return new PlaybackTransfer($transfer['id'], $transfer['source_incarnation_id'], $transfer['target_incarnation_id'], $transfer['desired_state'], (float) $transfer['position'], $transfer['state']);
    }

    /** @param array<string,mixed> $input */
    private function expectRevision(ListeningSession $session, array $input): void
    {
        if (!isset($input['expectedRevision']) || !is_int($input['expectedRevision']) || $input['expectedRevision'] !== (int) $session->revision()) {
            throw new ConflictException('Playback revision conflict.');
        }
    }

    private function assertPlayable(Uuid $owner, PlaybackMedia $media): void
    {
        if ($media->kind === 'song') {
            $this->mediaAccess->assertTrackAccess($owner, $media->publicId);
        } else {
            $this->mediaAccess->assertVideoAccess($owner, $media->publicId);
        }
    }

    /** @param array<string,mixed> $input */
    private function replayRequest(ListeningSession $session, string $requestId, PlaybackOperation $operation, array $input): bool
    {
        return $this->store->rememberRequest($session->getId()->toString(), $requestId, $this->requestDigest($operation, $input));
    }

    /** @param array<string,mixed> $input */
    private function requestDigest(PlaybackOperation $operation, array $input): string
    {
        unset($input['expectedRevision'],$input['type']);
        if ($operation === PlaybackOperation::Control) {
            unset($input['incarnationId']);
        }
        ksort($input);
        return hash('sha256', json_encode(['operation' => $operation->value, 'input' => $input], JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $input */
    private function uuid(array $input, string $key): string
    {
        if (!isset($input[$key]) || !is_string($input[$key])) {
            throw new InvalidArgumentException($key . ' must be a UUID.');
        }
        return Uuid::fromString($input[$key])->toString();
    }

    /** @param array<string,mixed> $input */
    private function position(array $input): float
    {
        $position = $input['position'] ?? null;
        if ((!is_int($position) && !is_float($position)) || !is_finite((float) $position) || $position < 0) {
            throw new InvalidArgumentException('Position must be finite and nonnegative.');
        }
        return (float) $position;
    }

    /** @param array<string,mixed> $input */
    private function volume(array $input): float
    {
        $volume = $input['volume'] ?? null;
        if ((!is_int($volume) && !is_float($volume)) || !is_finite((float) $volume) || $volume < 0 || $volume > 1) {
            throw new InvalidArgumentException('Volume must be finite and between zero and one.');
        }
        return (float) $volume;
    }

    private function media(mixed $media): ?PlaybackMedia
    {
        if ($media === null) {
            return null;
        }
        if (!is_array($media) || !in_array($media['kind'] ?? null, ['song', 'video'], true) || !is_string($media['publicId'] ?? null)) {
            throw new InvalidArgumentException('Media requires a song or video public identity.');
        }
        return new PlaybackMedia($media['kind'], PublicId::fromString($media['publicId']));
    }
}
