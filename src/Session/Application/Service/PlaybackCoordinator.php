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
    /** Controls that can start playback on the runner that executes them. */
    private const array STARTING_CONTROLS = ['play', 'load', 'seek'];

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
                $this->expireTransfer($session, $transfer);
                $transfer = $this->store->currentTransfer($session->getId()->toString());
            }
            $this->expireControl($session);
            switch ($operation) {
                case PlaybackOperation::Endpoint:
                    $input['deviceId'] = $this->uuid($input, 'deviceId');
                    $input['endpointId'] = $this->uuid($input, 'endpointId');
                    $input['pageId'] = $this->pageIdentity($input);
                    $registration = $this->store->register($owner, $input);
                    $earlierPages = $input['pageId'] === null ? [] : $this->earlierPagesOfTab($session, $transfer, $registration);
                    // The same page registering again is one live document that never stopped answering
                    // for its players, as after its socket was replaced. Its new incarnation takes the
                    // earlier one's place and its unanswered commands; nothing is released and the
                    // revision stands, because an answer is accepted only at its command's own revision.
                    foreach ($earlierPages as $earlier) {
                        if ($earlier['page_id'] === $input['pageId']) {
                            if ($session->activeIncarnationId()?->toString() === $earlier['id']) {
                                $session->succeedRunner(Uuid::fromString($registration['incarnationId']));
                            }
                            $this->store->succeedIncarnation($earlier['id'], $registration['incarnationId'], (int) $registration['epoch']);
                        }
                    }
                    // Registering a tab again retires its previous incarnation. The answer to the
                    // registration must already show a runner that this proves gone as released, or the
                    // tab would refuse its own first Play until the next relayed snapshot.
                    $this->releaseGoneRunner($session, $transfer);
                    // A tab holds one live document at a time, so a registration from another page
                    // proves the earlier page gone, whatever the session last knew about it. The
                    // proofs above ran first: what they release keeps the state it confirmed.
                    foreach ($earlierPages as $earlier) {
                        if ($earlier['page_id'] !== $input['pageId']) {
                            $this->releaseIncarnation($session, $earlier['id'], null);
                            $this->store->retireIncarnation($earlier['id'], stopped: true);
                        }
                    }
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
                    // A runner that is provably gone and idle is no source: the target starts directly.
                    $this->releaseGoneRunner($session, $transfer);
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
                        if ($this->goneAndStopped($this->store->endpoint($owner, $sourceId))) {
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
                        if ($state === 'stopped' && $session->desiredState() !== PlaybackState::Stopped) {
                            // A runner that stops without being told to has lost its playback, as after
                            // an outage that outlived its registration. Its own stop report is the proof
                            // its silence needs: the session continues without an executor, so the same
                            // browser can start again. A deliberate stop keeps its runner and selection
                            // for as long as that runner stays registered.
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
                    $pending = $this->store->pendingControl($session->getId()->toString());
                    if ($pending !== null) {
                        if ($action !== 'stop') {
                            throw new ConflictException('A runner control is awaiting acknowledgement.');
                        }
                        do {
                            $this->supersedeControl($session, $pending);
                            $pending = $this->store->pendingControl($session->getId()->toString());
                        } while ($pending !== null);
                    } else {
                        // The request already passed the revision check, so the release cannot refuse it.
                        // The control is then retained without a runner, as for a session nobody plays.
                        $this->releaseGoneRunner($session, $transfer);
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
                    $this->releaseGoneRunner($session, $transfer);
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
        $checkpoint = $this->checkpoint($input['checkpoint'] ?? null);
        if ($this->releaseIncarnation($session, $incarnation, $checkpoint)) {
            $this->store->saveSession($session);
        }
        $result = ['incarnationId' => $incarnation, 'outcome' => 'recovered', 'revision' => $session->revision()];
        $this->store->completeLocalRecovery($incarnation, $result);
        return $this->store->snapshot($session, null) + ['recoveryResult' => $result];
    }

    /**
     * Settles the session for one incarnation that will not answer again, wherever the session still
     * names it. Its unanswered commands are closed. As the runner, or as the source of a transfer whose
     * target was not yet asked to start, it leaves the session paused without a runner. As the target
     * of a transfer it fails the transfer, and the source keeps the session unless it is gone as well.
     * As the source of a transfer whose target was asked to start it changes nothing: the transfer goes
     * on. The cursor is the given checkpoint's where it describes the session as it stands, and the
     * one last recorded otherwise.
     *
     * @param array{media:?PlaybackMedia,currentTrackIndex:int,revision:int,position:float}|null $checkpoint
     * @return bool Whether the session changed. The caller saves it.
     */
    private function releaseIncarnation(ListeningSession $session, string $incarnation, ?array $checkpoint): bool
    {
        $owner = $session->getUserId()->toString();
        $transfer = $this->store->currentTransfer($session->getId()->toString());
        $source = $transfer !== null && $transfer['source_incarnation_id'] === $incarnation;
        $target = $transfer !== null && $transfer['target_incarnation_id'] === $incarnation;
        $active = $session->activeIncarnationId()?->toString() === $incarnation;
        $preserveTarget = $source && in_array($transfer['state'], ['starting', 'uncertain'], true);
        $this->store->supersedeIncarnationCommands($incarnation);
        if ($preserveTarget || !($source || $target || ($transfer === null && $active))) {
            return false;
        }
        $targetPreparing = $target && $transfer['state'] === 'preparing';
        if ($transfer !== null) {
            $this->store->cancelCommands($transfer['id']);
            $transfer['state'] = 'failed';
        }
        if ($targetPreparing) {
            // Nothing was handed over yet, so whoever ran playback still does.
            $this->fallBackToRunner($session);
            $session->advanceRevision();
        } else {
            $sourceId = $target ? $transfer['source_incarnation_id'] : null;
            $sourceEndpoint = $sourceId === null ? null : $this->store->endpoint($owner, $sourceId);
            // A source that is itself recorded gone and stopped does not get the session back.
            $keepSource = $sourceEndpoint !== null && !$this->goneAndStopped($sourceEndpoint);
            $sourceStopped = !$keepSource || $this->store->sourceQuiesced($transfer['id'], $sourceId);
            if ($checkpoint === null) {
                $session->releaseGoneIncarnation(!$keepSource, $sourceStopped);
            } else {
                $session->recoverLocalRunner($checkpoint['media'], $checkpoint['currentTrackIndex'], $checkpoint['revision'], $checkpoint['position'], !$keepSource, $sourceStopped);
            }
            if ($keepSource) {
                $session->restoreSelection(Uuid::fromString($sourceEndpoint['destination_id']));
                if (!$sourceStopped) {
                    $obligation = (new Uuid())->toString();
                    $this->enqueue($session, $obligation, $obligation, $sourceId, 'stop', hash('sha256', $obligation));
                }
            }
        }
        if ($transfer !== null) {
            $transfer['revision'] = $session->revision();
            $this->store->saveTransfer($transfer);
        }
        return true;
    }

    /**
     * A failed transfer leaves the session with the runner it had. A runner whose incarnation is
     * retired and whose own last report is a stop is gone and silent, so the session is released
     * from it instead of being handed back to it.
     */
    private function fallBackToRunner(ListeningSession $session): void
    {
        $runnerId = $session->activeIncarnationId()?->toString();
        $runner = $runnerId === null ? null : $this->store->endpoint($session->getUserId()->toString(), $runnerId);
        if ($runner !== null && $this->goneAndStopped($runner)) {
            $session->releaseSilentRunner();
            return;
        }
        $session->restoreSelection($runner === null ? null : Uuid::fromString($runner['destination_id']));
    }

    /**
     * Whether an incarnation's registration was ended and its own last report is a stop. Nothing shown
     * to it since that report can have started it: a start it was shown and did not answer marks it unknown.
     *
     * @param array<string,mixed> $endpoint
     */
    private function goneAndStopped(array $endpoint): bool
    {
        return ($endpoint['retired_at'] ?? null) !== null && ($endpoint['observed_state'] ?? null) === 'stopped';
    }

    /**
     * The earlier incarnations of the tab that just registered which the session still names, as the
     * target or the source of its open transfer or as its runner, in that order, and which registered
     * with a page identity. One that registered without names no page, and that is never proof.
     *
     * @param array<string,mixed>|null $transfer
     * @param array<string,mixed> $registration
     * @return list<array<string,mixed>>
     */
    private function earlierPagesOfTab(ListeningSession $session, ?array $transfer, array $registration): array
    {
        $named = [$transfer['target_incarnation_id'] ?? null, $transfer['source_incarnation_id'] ?? null, $session->activeIncarnationId()?->toString()]
            |> array_filter(...)
            |> array_unique(...);
        $owner = $session->getUserId()->toString();
        $endpoints = array_map(fn(string $incarnationId): array => $this->store->endpoint($owner, $incarnationId), $named);
        return array_values(array_filter(
            $endpoints,
            static fn(array $endpoint): bool => $endpoint['public_id'] === $registration['destinationId'] && $endpoint['page_id'] !== null,
        ));
    }

    private function expireControl(ListeningSession $session): void
    {
        $command = $this->store->pendingControl($session->getId()->toString());
        if ($command === null || new DateTimeImmutable($command['expires_at']) > new DateTimeImmutable()) {
            return;
        }
        if (in_array($command['type'], ['volume', 'queue'], true)) {
            // Neither can start or stop playback, so the session keeps what it wants and owes no stop.
            // One that never left the server is settled as such; one the runner was shown stays a
            // command the runner may have applied.
            $session->advanceRevision();
            if ($this->neverEffective($session, $command, restoresConfirmedState: false)) {
                $this->store->settleNeverEffective($command['attempt_id'], $session->revision());
            } else {
                $this->store->cancelCommands($command['attempt_id']);
            }
            return;
        }
        if ($this->neverEffective($session, $command, restoresConfirmedState: true)) {
            // The runner was confirmed idle and cannot have been started by this command, so nothing
            // is uncertain: the session wants the confirmed state again and no stop is owed.
            $session->settleUnansweredControl();
            $this->store->settleNeverEffective($command['attempt_id'], $session->revision());
            return;
        }
        $this->store->cancelCommands($command['attempt_id']);
        if ($command['disclosed_at'] !== null && in_array($command['type'], self::STARTING_CONTROLS, true)) {
            // The runner was shown a control that can start playback and did not answer. Its own last
            // report no longer describes it, so neither the stop issued below nor any later stop can
            // settle as harmless before the runner answers.
            $this->store->markRunnerUnknown($command['incarnation_id']);
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
     * A stop replaces a control its runner has not answered. A control that cannot have changed the
     * runner settles as never effective and leaves no trace on what is known about the runner. One
     * the runner was shown and that can start playback may have run: the runner turns unknown, so the
     * stop that follows cannot later pass as one that changed nothing.
     *
     * @param array<string,mixed> $command
     */
    private function supersedeControl(ListeningSession $session, array $command): void
    {
        if ($this->neverEffective($session, $command, restoresConfirmedState: false)) {
            $this->store->settleNeverEffective($command['attempt_id'], $session->revision());
            return;
        }
        $this->store->cancelCommands($command['attempt_id']);
        if ($command['disclosed_at'] !== null && in_array($command['type'], self::STARTING_CONTROLS, true)) {
            $session->retainUncertainRunner();
            $this->store->markRunnerUnknown($command['incarnation_id']);
        }
    }

    /**
     * An unanswered control changed nothing on a browser runner in two cases. It never left the
     * server; or it is a stop or a pause, which can only silence, and the runner was confirmed idle
     * by the session and by its own last report. When settling the control returns the session to
     * its confirmed state, the runner must be confirmed idle in the first case too. A replaced
     * control and an expired volume or queue edit return the session to nothing, so for them never
     * having left the server is enough. A registration that lapsed is no part of this proof: a
     * runner that was shown a play and then cut off keeps playing.
     *
     * @param array<string,mixed> $command
     */
    private function neverEffective(ListeningSession $session, array $command, bool $restoresConfirmedState): bool
    {
        $neverShown = $command['disclosed_at'] === null;
        $idle = in_array($session->observedState(), [ObservedPlaybackState::Stopped, ObservedPlaybackState::Paused], true);
        $provenByAbsence = $neverShown && !$restoresConfirmedState;
        if (!$provenByAbsence && (!$idle || (!$neverShown && !in_array($command['type'], ['stop', 'pause'], true)))) {
            return false;
        }
        $runner = $this->store->endpoint($session->getUserId()->toString(), $command['incarnation_id']);
        return $runner['kind'] === 'browser'
            && ($provenByAbsence || in_array($runner['observed_state'], ['stopped', 'paused'], true));
    }

    /**
     * A confirmed paused or stopped browser runner keeps its place only while it stays registered. Once
     * it has gone, no destination carries it and every client would be refused playback for good, so
     * the session continues at its cursor without an executor. The store holds the proof; a session
     * whose state is unknown, or that has a transfer or a command outstanding, releases nothing.
     *
     * @param array<string,mixed>|null $transfer
     */
    private function releaseGoneRunner(ListeningSession $session, ?array $transfer): void
    {
        if ($transfer !== null || $session->activeIncarnationId() === null) {
            return;
        }
        if ($session->desiredState() === PlaybackState::Paused
            && $session->observedState() === ObservedPlaybackState::Paused
            && $this->store->retireConfirmedPausedBrowser($session)) {
            $session->releaseConfirmedPausedRunner();
        } elseif ($session->desiredState() === PlaybackState::Stopped
            && $session->observedState() === ObservedPlaybackState::Stopped
            && $this->store->retireConfirmedStoppedBrowser($session)) {
            $session->releaseConfirmedStoppedRunner();
        }
    }

    /**
     * A transfer that ran out of time fails when its target was never shown a start: nothing new can be
     * playing. Once the target was shown its start it may be playing, and the transfer stays uncertain
     * until the target confirms a stop.
     *
     * @param array<string,mixed> $transfer
     */
    private function expireTransfer(ListeningSession $session, array $transfer): void
    {
        $model = $this->model($transfer);
        $quiescing = $model->state() === 'quiescing';
        $model->expire($this->store->attemptDisclosed($transfer['id'], 'start'));
        $this->store->cancelCommands($transfer['id']);
        $session->advanceRevision();
        $this->persistTransfer($session, $transfer, $model);
        if ($model->state() === 'uncertain') {
            $session->retainUncertainRunner();
            return;
        }
        $this->fallBackToRunner($session);
        if ($quiescing
            && !in_array($session->observedState(), [ObservedPlaybackState::Stopped, ObservedPlaybackState::Paused], true)
            && $this->store->attemptDisclosed($transfer['id'], 'quiesce')) {
            // The source was shown the quiesce and did not answer: it may have paused or may still play.
            $session->retainUncertainRunner();
        }
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
                $this->fallBackToRunner($session);
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
        if ($command['type'] === 'queue') {
            // A queue edit cannot start, stop or move playback, so its answer says nothing about
            // playback, applied or rejected: what the session and the runner last knew stands. The
            // answer records the cursor and renews the runner's presence.
            $cursor = isset($input['position']) ? $this->position($input) : $session->getPosition();
            $this->store->acknowledgeCommand($command['id'], $outcome, $rejectionReason);
            $session->observePlayback($session->observedState(), $cursor);
            $this->store->reportCursor($endpoint['id'], $cursor);
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

    /**
     * The page identity a registration names, or null when it names none.
     *
     * @param array<string,mixed> $input
     */
    private function pageIdentity(array $input): ?string
    {
        $pageId = $input['pageId'] ?? null;
        if ($pageId === null) {
            return null;
        }
        try {
            return strtolower($this->uuid($input, 'pageId'));
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('pageId must be a UUID.');
        }
    }

    /**
     * The cursor an endpoint held when it went away, with the session revision, media and queue position it belongs to.
     *
     * @return array{media:?PlaybackMedia,currentTrackIndex:int,revision:int,position:float}
     */
    private function checkpoint(mixed $checkpoint): array
    {
        if (!is_array($checkpoint) || !array_key_exists('media', $checkpoint)
            || !isset($checkpoint['revision'], $checkpoint['currentTrackIndex'])
            || !is_int($checkpoint['revision']) || $checkpoint['revision'] < 0
            || !is_int($checkpoint['currentTrackIndex']) || $checkpoint['currentTrackIndex'] < 0) {
            throw new InvalidArgumentException('Invalid local recovery checkpoint.');
        }
        return ['media' => $checkpoint['media'] === null ? null : $this->media($checkpoint['media']),
            'currentTrackIndex' => $checkpoint['currentTrackIndex'], 'revision' => $checkpoint['revision'], 'position' => $this->position($checkpoint)];
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
