<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine;

use App\Session\Application\Port\PlaybackStoreInterface;
use App\Shared\Domain\Model\PublicId;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\ListeningSession\ListeningSessionState;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Session\Domain\Model\Playback\ObservedPlaybackState;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use App\Shared\Application\Exception\ConflictException;

final readonly class PlaybackStore implements PlaybackStoreInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    public function lockSession(string $userId): ListeningSession
    {
        // The account lock also serializes the first insert, before a session row exists.
        $this->connection()->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:owner,0))', ['owner' => $userId]);
        $this->connection()->executeStatement(<<<'SQL'
            INSERT INTO listening_sessions(id,public_id,user_id,queue,current_track_index,position,playback_state,created_at,updated_at)
            VALUES(:id,:publicId,:owner,'[]',0,0,'stopped',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            ON CONFLICT(user_id) DO NOTHING
            SQL, ['id' => (new Uuid())->toString(), 'publicId' => (new PublicId())->toString(), 'owner' => $userId]);
        $row = $this->connection()->fetchAssociative('SELECT * FROM listening_sessions WHERE user_id=:owner FOR UPDATE', ['owner' => $userId]);
        if ($row === false) {
            throw new ConflictException('Playback session is unavailable.');
        }
        return $this->hydrate($this->decode($row));
    }

    public function saveSession(ListeningSession $aggregate): void
    {
        $session = $this->sessionRow($aggregate);
        $this->connection()->executeStatement(<<<'SQL'
            UPDATE listening_sessions SET revision=:revision, media=CAST(:media AS jsonb),queue=CAST(:queue AS jsonb),
                current_track_index=:index,position=:position,playback_state=:desired,desired_state=:desired,
                observed_state=:observed,selected_destination_id=:destination,active_incarnation_id=:incarnation,
                updated_at=CURRENT_TIMESTAMP,last_used_at=CURRENT_TIMESTAMP WHERE id=:id
            SQL, [
            'revision' => $session['revision'], 'media' => json_encode($session['media'], JSON_THROW_ON_ERROR),
            'queue' => json_encode($session['queue'], JSON_THROW_ON_ERROR), 'index' => $session['current_track_index'],
            'position' => $session['position'], 'desired' => $session['desired_state'], 'observed' => $session['observed_state'],
            'destination' => $session['selected_destination_id'], 'incarnation' => $session['active_incarnation_id'], 'id' => $session['id'],
        ]);
    }

    public function retireConfirmedPausedBrowser(ListeningSession $session): bool
    {
        // The caller holds the account/session lock. All commands and observations use that lock.
        // This proof covers managed browser execution only; independent external routes need their own proof.
        if ($session->activeIncarnationId() === null || $session->desiredState() !== PlaybackState::Paused
            || $session->observedState() !== ObservedPlaybackState::Paused) {
            return false;
        }
        return $this->connection()->fetchOne(<<<'SQL'
            UPDATE playback_destination_incarnations i SET retired_at=coalesce(i.retired_at,CURRENT_TIMESTAMP)
            FROM playback_destinations d
            WHERE i.id=:incarnation AND i.user_id=:owner AND d.id=i.destination_id AND d.kind='browser'
                AND (i.expires_at<=CURRENT_TIMESTAMP OR i.retired_at IS NOT NULL)
                AND i.observed_state IN ('paused','stopped')
                AND EXISTS (
                    SELECT 1 FROM playback_commands proof
                    WHERE proof.session_id=:session AND proof.incarnation_id=i.id AND proof.epoch=i.epoch
                        AND proof.revision=:ackRevision AND proof.acknowledged_at IS NOT NULL
                        AND ((proof.type='pause' AND proof.outcome='paused')
                            OR (proof.type='quiesce' AND proof.outcome='quiesced')
                            OR (proof.type='stop' AND proof.outcome='stopped'))
                        AND NOT EXISTS (
                            SELECT 1 FROM playback_commands later
                            WHERE later.session_id=proof.session_id AND later.id<>proof.id
                                AND (later.revision>=proof.revision OR later.created_at>proof.created_at)
                        )
                )
                AND NOT EXISTS (SELECT 1 FROM playback_commands pending
                    WHERE pending.session_id=:session AND pending.acknowledged_at IS NULL)
                AND NOT EXISTS (SELECT 1 FROM playback_transfers transfer
                    WHERE transfer.session_id=:session AND transfer.state NOT IN ('committed','failed'))
            RETURNING i.id
            SQL, ['incarnation' => $session->activeIncarnationId()->toString(),
                'owner' => $session->getUserId()->toString(), 'session' => $session->getId()->toString(),
                'ackRevision' => $session->revision() - 1]) !== false;
    }

    public function register(string $userId, array $input): array
    {
        $deviceId = Uuid::fromString($input['deviceId'])->toString();
        $endpointId = Uuid::fromString($input['endpointId'])->toString();
        $device = $this->connection()->fetchAssociative('SELECT * FROM devices WHERE user_id=:owner AND device_id=:device', ['owner' => $userId, 'device' => $deviceId]);
        if ($device === false) {
            throw new ConflictException('Register this browser device first.');
        }
        $eligible = $input['localRecoveryEligible'] ?? false;
        if (!is_bool($eligible)) {
            throw new \InvalidArgumentException('Local recovery eligibility must be a boolean.');
        }
        $tabId = isset($input['tabId']) ? Uuid::fromString($input['tabId'])->toString() : null;
        if ($eligible && $tabId === null) {
            throw new \InvalidArgumentException('Local recovery requires a tab identity.');
        }
        $name = $input['name'] ?? $device['name'] ?? 'Browser';
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 128) {
            throw new \InvalidArgumentException('Endpoint name must contain 1 to 128 characters.');
        }
        $capabilities = $input['capabilities'] ?? ['song', 'video'];
        if (!is_array($capabilities) || !array_is_list($capabilities) || array_intersect($capabilities, ['song', 'video']) === [] || array_filter($capabilities, static fn(mixed $kind): bool => !is_string($kind)) !== [] || array_diff($capabilities, ['song', 'video', 'volume']) !== []) {
            throw new \InvalidArgumentException('Endpoint capabilities require song or video and may include volume.');
        }
        $this->connection()->executeStatement(<<<'SQL'
            INSERT INTO playback_destinations(id,public_id,user_id,device_id,endpoint_id,name,capabilities,created_at)
            VALUES(:id,:publicId,:owner,:device,:endpoint,:name,CAST(:capabilities AS jsonb),CURRENT_TIMESTAMP)
            ON CONFLICT(user_id,device_id,endpoint_id) DO UPDATE SET name=EXCLUDED.name,capabilities=EXCLUDED.capabilities
            SQL, ['id' => (new Uuid())->toString(), 'publicId' => (new PublicId())->toString(), 'owner' => $userId,
            'device' => $deviceId, 'endpoint' => $endpointId, 'name' => $name,
            'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR)]);
        $destination = $this->connection()->fetchAssociative('SELECT * FROM playback_destinations WHERE user_id=:owner AND device_id=:device AND endpoint_id=:endpoint', ['owner' => $userId, 'device' => $deviceId, 'endpoint' => $endpointId]);
        if ($destination === false) {
            throw new ConflictException('Endpoint registration failed.');
        }
        $epoch = (int) $this->connection()->fetchOne('SELECT coalesce(max(epoch),0)+1 FROM playback_destination_incarnations WHERE destination_id=:destination', ['destination' => $destination['id']]);
        $this->connection()->executeStatement('UPDATE playback_destination_incarnations SET retired_at=CURRENT_TIMESTAMP WHERE destination_id=:destination AND retired_at IS NULL', ['destination' => $destination['id']]);
        $incarnation = (new Uuid())->toString();
        $capability = bin2hex(random_bytes(32));
        $this->connection()->executeStatement(<<<'SQL'
            INSERT INTO playback_destination_incarnations(id,destination_id,user_id,epoch,capability_hash,expires_at)
            VALUES(:id,:destination,:owner,:epoch,:hash,CURRENT_TIMESTAMP+INTERVAL '30 seconds')
            SQL, ['id' => $incarnation, 'destination' => $destination['id'], 'owner' => $userId, 'epoch' => $epoch, 'hash' => hash('sha256', $capability)]);
        $result = ['destinationId' => $destination['public_id'], 'incarnationId' => $incarnation, 'epoch' => $epoch, 'capability' => $capability];
        if ($eligible) {
            $credential = bin2hex(random_bytes(32));
            $expires = new \DateTimeImmutable('+30 days');
            $this->connection()->executeStatement(<<<'SQL'
                UPDATE playback_destination_incarnations SET tab_id=:tab,local_recovery_eligible=TRUE,
                    recovery_credential_hash=:hash,recovery_expires_at=:expires WHERE id=:id
                SQL, ['tab' => $tabId, 'hash' => hash('sha256', $credential),
                'expires' => $expires->format('Y-m-d H:i:s.uP'), 'id' => $incarnation]);
            $result += ['recoveryCredential' => $credential, 'recoveryExpiresAt' => $expires->format(\DateTimeInterface::ATOM), 'recoveryTabId' => $tabId];
        }
        return $result;
    }

    public function endpoint(string $userId, string $incarnationId, ?string $capability = null): array
    {
        $row = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT i.*,d.public_id,d.capabilities,(i.expires_at>CURRENT_TIMESTAMP AND i.retired_at IS NULL) available
            FROM playback_destination_incarnations i JOIN playback_destinations d ON d.id=i.destination_id
            WHERE i.id=:id AND i.user_id=:owner
            SQL, ['id' => Uuid::fromString($incarnationId)->toString(), 'owner' => $userId]);
        if ($row === false || ($capability !== null && !hash_equals($row['capability_hash'], hash('sha256', $capability)))) {
            throw new ConflictException('Endpoint authority is invalid.');
        }
        // Retirement never implies silence, and the old runner may still acknowledge a stop.
        return $this->decode($row);
    }

    public function localRecovery(string $owner, string $incarnationId, string $tabId, #[\SensitiveParameter] string $credential): array
    {
        $row = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT * FROM playback_destination_incarnations WHERE id=:id AND user_id=:owner AND tab_id=:tab FOR UPDATE
            SQL, ['id' => $incarnationId, 'owner' => $owner, 'tab' => $tabId]);
        if ($row === false || !$row['local_recovery_eligible']
            || $row['recovery_revoked_at'] !== null || !is_string($row['recovery_credential_hash'])
            || !hash_equals($row['recovery_credential_hash'], hash('sha256', $credential))) {
            throw new ConflictException('Local recovery authority is invalid or expired.');
        }
        $valid = $this->connection()->fetchOne('SELECT recovery_expires_at>clock_timestamp() FROM playback_destination_incarnations WHERE id=:id', ['id' => $incarnationId]);
        if (!$valid) {
            throw new ConflictException('Local recovery authority is invalid or expired.');
        }
        $row['recovery_result'] = $row['recovery_result'] === null ? null : json_decode($row['recovery_result'], true, flags: JSON_THROW_ON_ERROR);
        return $row;
    }

    public function completeLocalRecovery(string $incarnationId, array $result): void
    {
        $this->connection()->executeStatement(<<<'SQL'
            UPDATE playback_destination_incarnations SET retired_at=coalesce(retired_at,CURRENT_TIMESTAMP),
                observed_state='stopped',recovery_result=CAST(:result AS jsonb) WHERE id=:id
            SQL, ['id' => $incarnationId, 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);
    }

    public function supersedeIncarnationCommands(string $incarnationId): void
    {
        $this->connection()->executeStatement("UPDATE playback_commands SET outcome='superseded',acknowledged_at=CURRENT_TIMESTAMP WHERE incarnation_id=:id AND acknowledged_at IS NULL", ['id' => $incarnationId]);
    }

    public function sourceQuiesced(string $attemptId, string $sourceId): bool
    {
        return (bool) $this->connection()->fetchOne("SELECT EXISTS(SELECT 1 FROM playback_commands WHERE attempt_id=:attempt AND incarnation_id=:source AND type='quiesce' AND outcome='quiesced')", ['attempt' => $attemptId, 'source' => $sourceId]);
    }

    public function currentTransfer(string $sessionId): ?array
    {
        $row = $this->connection()->fetchAssociative("SELECT * FROM playback_transfers WHERE session_id=:id AND state NOT IN ('committed','failed')", ['id' => $sessionId]);
        return $row === false ? null : $this->decode($row);
    }

    public function saveTransfer(array $transfer): void
    {
        $this->connection()->executeStatement(<<<'SQL'
            INSERT INTO playback_transfers(id,session_id,user_id,request_id,request_digest,source_incarnation_id,target_incarnation_id,state,desired_state,position,revision,expires_at,created_at)
            VALUES(:id,:session,:owner,:request,:digest,:source,:target,:state,:desired,:position,:revision,CURRENT_TIMESTAMP+INTERVAL '20 seconds',CURRENT_TIMESTAMP)
            ON CONFLICT(id) DO UPDATE SET state=EXCLUDED.state,position=EXCLUDED.position,revision=EXCLUDED.revision,expires_at=EXCLUDED.expires_at
            SQL, ['id' => $transfer['id'], 'session' => $transfer['session_id'], 'owner' => $transfer['user_id'],
            'request' => $transfer['request_id'], 'digest' => $transfer['request_digest'], 'source' => $transfer['source_incarnation_id'], 'target' => $transfer['target_incarnation_id'],
            'state' => $transfer['state'], 'desired' => $transfer['desired_state'], 'position' => $transfer['position'], 'revision' => $transfer['revision']]);
    }

    public function insertCommand(array $command): void
    {
        $this->connection()->executeStatement(<<<'SQL'
            INSERT INTO playback_commands(id,session_id,user_id,attempt_id,request_id,request_digest,incarnation_id,epoch,revision,type,payload,outcome,acknowledged_at,expires_at,created_at)
            VALUES(:id,:session,:owner,:attempt,:request,:digest,:incarnation,:epoch,:revision,:type,CAST(:payload AS jsonb),:outcome,CASE WHEN :retained=1 THEN CURRENT_TIMESTAMP ELSE NULL END,CURRENT_TIMESTAMP+INTERVAL '20 seconds',CURRENT_TIMESTAMP)
            ON CONFLICT(session_id,request_id,type,incarnation_id,revision) DO NOTHING
            SQL, ['id' => $command['id'], 'session' => $command['session_id'], 'owner' => $command['user_id'],
            'attempt' => $command['attempt_id'], 'request' => $command['request_id'], 'digest' => $command['request_digest'], 'outcome' => $command['incarnation_id'] === null ? 'retained' : null, 'retained' => $command['incarnation_id'] === null ? 1 : 0, 'incarnation' => $command['incarnation_id'],
            'epoch' => $command['epoch'], 'revision' => $command['revision'], 'type' => $command['type'],
            'payload' => json_encode($command['payload'], JSON_THROW_ON_ERROR)]);
    }

    public function command(string $userId, string $commandId): array
    {
        $row = $this->connection()->fetchAssociative('SELECT * FROM playback_commands WHERE id=:id AND user_id=:owner', ['id' => Uuid::fromString($commandId)->toString(), 'owner' => $userId]);
        if ($row === false) {
            throw new ConflictException('Playback command is unavailable.');
        }
        return $this->decode($row);
    }

    public function acknowledgeCommand(string $commandId, string $outcome, ?string $rejectionReason = null): void
    {
        $this->connection()->executeStatement('UPDATE playback_commands SET outcome=:outcome,rejection_reason=:reason,acknowledged_at=CURRENT_TIMESTAMP WHERE id=:id AND acknowledged_at IS NULL', ['outcome' => $outcome, 'reason' => $rejectionReason, 'id' => $commandId]);
    }

    public function requestedCommand(string $sessionId, string $requestId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT id,request_id,outcome,rejection_reason FROM playback_commands WHERE session_id=:session AND request_id=:request ORDER BY created_at,id LIMIT 1',
            ['session' => $sessionId, 'request' => $requestId],
        );
        return $row === false ? null : $row;
    }

    public function requestedTransfer(string $sessionId, string $requestId): ?array
    {
        $row = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT t.id,t.request_id,t.state,
                (SELECT c.rejection_reason FROM playback_commands c
                 WHERE c.session_id=t.session_id AND c.attempt_id=t.id AND c.outcome='rejected'
                 ORDER BY c.created_at,c.id LIMIT 1) AS rejection_reason
            FROM playback_transfers t WHERE t.session_id=:session AND t.request_id=:request
            SQL, ['session' => $sessionId, 'request' => $requestId]);
        return $row === false ? null : $row;
    }

    public function cancelCommands(string $attemptId): void
    {
        $this->connection()->executeStatement("UPDATE playback_commands SET outcome='superseded',acknowledged_at=CURRENT_TIMESTAMP WHERE attempt_id=:attempt AND acknowledged_at IS NULL", ['attempt' => $attemptId]);
    }

    public function observe(string $incarnationId, string $state, float $position, ?float $volume = null): void
    {
        $this->connection()->executeStatement("UPDATE playback_destination_incarnations SET observed_state=:state,position=:position,observed_volume=coalesce(CAST(:volume AS double precision),observed_volume),expires_at=CURRENT_TIMESTAMP+INTERVAL '30 seconds' WHERE id=:id", ['state' => $state, 'position' => $position, 'volume' => $volume, 'id' => $incarnationId]);
    }

    public function observeVolume(string $incarnationId, float $volume): void
    {
        $this->connection()->executeStatement("UPDATE playback_destination_incarnations SET observed_volume=:volume,expires_at=CURRENT_TIMESTAMP+INTERVAL '30 seconds' WHERE id=:id", ['volume' => $volume, 'id' => $incarnationId]);
    }

    public function snapshot(ListeningSession $aggregate, ?string $incarnationId): array
    {
        $session = $this->sessionRow($aggregate);
        $destinations = $this->connection()->fetchAllAssociative(<<<'SQL'
            SELECT d.public_id AS "destinationId",d.name,d.kind,d.capabilities,
                i.id AS "incarnationId",i.epoch,i.observed_state AS "observedState",i.position,i.observed_volume AS "observedVolume",
                coalesce(i.expires_at>CURRENT_TIMESTAMP AND i.retired_at IS NULL,false) AS available
            FROM playback_destinations d LEFT JOIN LATERAL (
                SELECT current_incarnation.* FROM playback_destination_incarnations current_incarnation
                WHERE current_incarnation.destination_id=d.id ORDER BY current_incarnation.epoch DESC LIMIT 1
            ) i ON true
            WHERE d.user_id=:owner ORDER BY d.created_at,i.epoch
            SQL, ['owner' => $session['user_id']]);
        $commands = $incarnationId === null || $this->connection()->getTransactionNestingLevel() > 1 ? [] : $this->connection()->fetchAllAssociative(<<<'SQL'
            SELECT id,attempt_id AS "attemptId",revision,incarnation_id AS "incarnationId",epoch,type,payload,expires_at AS "expiresAt"
            FROM playback_commands WHERE session_id=:session AND incarnation_id=:incarnation AND acknowledged_at IS NULL
            ORDER BY created_at,id
            SQL, ['session' => $session['id'], 'incarnation' => $incarnationId]);
        $selected = $session['selected_destination_id'] === null ? null : $this->connection()->fetchOne('SELECT public_id FROM playback_destinations WHERE id=:id', ['id' => $session['selected_destination_id']]);
        $active = $session['active_incarnation_id'] === null ? null : $this->connection()->fetchOne('SELECT d.public_id FROM playback_destination_incarnations i JOIN playback_destinations d ON d.id=i.destination_id WHERE i.id=:id', ['id' => $session['active_incarnation_id']]);
        $transfer = $this->currentTransfer($session['id']);
        return ['schemaVersion' => 1, 'publicId' => $session['public_id'], 'revision' => (int) $session['revision'],
            'media' => $session['media'], 'queue' => $session['queue'], 'currentTrackIndex' => (int) $session['current_track_index'],
            'position' => (float) $session['position'], 'playbackState' => $session['desired_state'], 'desiredState' => $session['desired_state'],
            'observedState' => $session['observed_state'], 'selectedDestinationId' => $selected, 'activeDestinationId' => $active,
            'activeIncarnationId' => $session['active_incarnation_id'],
            'transfer' => $transfer === null ? null : ['attemptId' => $transfer['id'], 'state' => $transfer['state'],
                'sourceIncarnationId' => $transfer['source_incarnation_id'], 'targetIncarnationId' => $transfer['target_incarnation_id'],
                'position' => (float) $transfer['position'], 'desiredState' => $transfer['desired_state']],
            'destinations' => array_map($this->decode(...), $destinations),
            'commands' => array_map(fn(array $command): array => [
                ...$this->decode($command),
                'expiresAt' => new \DateTimeImmutable($command['expiresAt'])->format(\DateTimeInterface::RFC3339_EXTENDED),
            ], $commands)];
    }

    public function hasPendingControl(string $sessionId): bool
    {
        return $this->pendingControl($sessionId) !== null;
    }

    public function pendingControl(string $sessionId): ?array
    {
        $command = $this->connection()->fetchAssociative("SELECT * FROM playback_commands WHERE session_id=:session AND acknowledged_at IS NULL AND type IN ('play','pause','seek','load','stop','volume','queue') ORDER BY created_at,id LIMIT 1", ['session' => $sessionId]);
        return $command === false ? null : $this->decode($command);
    }

    public function supersedeControls(string $sessionId): void
    {
        $this->connection()->executeStatement("UPDATE playback_commands SET outcome='superseded',acknowledged_at=CURRENT_TIMESTAMP WHERE session_id=:session AND acknowledged_at IS NULL AND type IN ('play','pause','seek','load','stop','volume','queue')", ['session' => $sessionId]);
    }

    public function rememberRequest(string $sessionId, string $requestId, string $digest): bool
    {
        $previous = $this->connection()->fetchOne('SELECT request_digest FROM playback_transfers WHERE session_id=:session AND request_id=:request UNION ALL SELECT request_digest FROM playback_commands WHERE session_id=:session AND request_id=:request LIMIT 1', ['session' => $sessionId, 'request' => $requestId]);
        if ($previous === false) {
            return false;
        }
        if (!hash_equals($previous, $digest)) {
            throw new ConflictException('The playback request identity has already been used for another payload.');
        }
        return true;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): ListeningSession
    {
        return ListeningSession::reconstitute(new ListeningSessionState(
            id: Uuid::fromString($row['id']),
            publicId: PublicId::fromString($row['public_id']),
            userId: Uuid::fromString($row['user_id']),
            activeDeviceId: $row['active_device_id'] === null ? null : Uuid::fromString($row['active_device_id']),
            queue: $row['queue'],
            currentTrackIndex: (int) $row['current_track_index'],
            position: (float) $row['position'],
            playbackState: $row['playback_state'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            updatedAt: new \DateTimeImmutable($row['updated_at']),
            lastUsedAt: $row['last_used_at'] === null ? null : new \DateTimeImmutable($row['last_used_at']),
            revision: (int) $row['revision'],
            media: $row['media'] === null ? null : new PlaybackMedia($row['media']['kind'], PublicId::fromString($row['media']['publicId'])),
            desiredState: PlaybackState::from($row['desired_state']),
            observedState: ObservedPlaybackState::from($row['observed_state']),
            selectedDestinationId: $row['selected_destination_id'] === null ? null : Uuid::fromString($row['selected_destination_id']),
            activeIncarnationId: $row['active_incarnation_id'] === null ? null : Uuid::fromString($row['active_incarnation_id']),
        ));
    }

    /** @return array<string,mixed> */
    private function sessionRow(ListeningSession $session): array
    {
        return ['id' => $session->getId()->toString(), 'public_id' => $session->getPublicId()->toString(), 'user_id' => $session->getUserId()->toString(),
            'revision' => $session->revision(), 'media' => $session->media()?->toArray(), 'queue' => $session->getQueue(),
            'current_track_index' => $session->getCurrentTrackIndex(), 'position' => $session->getPosition(), 'desired_state' => $session->desiredState()->value,
            'observed_state' => $session->observedState()->value, 'selected_destination_id' => $session->selectedDestinationId()?->toString(),
            'active_incarnation_id' => $session->activeIncarnationId()?->toString()];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode(array $row): array
    {
        foreach (['queue', 'media', 'capabilities', 'payload', 'recovery_result'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $row[$key] = json_decode($row[$key], true, 512, JSON_THROW_ON_ERROR);
            }
        }
        if (isset($row['observedVolume'])) {
            $row['observedVolume'] = (float) $row['observedVolume'];
        }
        return $row;
    }
}
