<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine;

use App\Session\Application\Port\PlaybackStoreInterface;
use App\Session\Infrastructure\Doctrine\Entity\DeviceEntity;
use App\Session\Infrastructure\Doctrine\Entity\ListeningSessionEntity;
use App\Session\Infrastructure\Doctrine\Entity\PlaybackCommandEntity;
use App\Session\Infrastructure\Doctrine\Entity\PlaybackDestinationEntity;
use App\Session\Infrastructure\Doctrine\Entity\PlaybackDestinationIncarnationEntity;
use App\Session\Infrastructure\Doctrine\Entity\PlaybackTransferEntity;
use App\Shared\Domain\Model\PublicId;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\ListeningSession\ListeningSessionState;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Session\Domain\Model\Playback\ObservedPlaybackState;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Type\InstantType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use App\Shared\Application\Exception\ConflictException;

/**
 * Reads go through the mapping as DQL and return plain rows, never managed entities: the coordinator
 * reads an endpoint before it holds the session lock, the relay serves every socket of a worker
 * through one entity manager, and a managed copy would go stale behind both.
 *
 * A row is created through the unit of work, flushed and detached in the method that creates it, so
 * later reads of the same operation see it and nothing managed outlives the call. A row is changed
 * by one DQL update whose predicate is the guard. The database clock stamps every instant except a
 * recovery credential's expiry: an update stamps in its statement, an insert with the transaction's
 * clock read beforehand. Five statements stay SQL, each for the reason given where it stands.
 */
final readonly class PlaybackStore implements PlaybackStoreInterface
{
    /** True while the incarnation's registration holds: neither lapsed nor retired. */
    private const string AVAILABLE = 'CASE WHEN i.expiresAt > CURRENT_TIMESTAMP() AND i.retiredAt IS NULL THEN true ELSE false END';
    private const string CONTROL_TYPES = "'play', 'pause', 'seek', 'load', 'stop', 'volume', 'queue'";
    /** Seconds a command or a transfer step waits for its answer. */
    private const int COMMAND_LIFETIME = 20;
    /** Seconds a registration holds after the runner last reported. */
    private const int PRESENCE_LIFETIME = 30;

    public function __construct(private EntityManagerInterface $entityManager) {}

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    public function lockSession(string $userId): ListeningSession
    {
        // The account lock also serializes the first insert, before a session row exists. DQL has no
        // advisory lock.
        $this->connection()->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:owner,0))', ['owner' => $userId]);
        $locked = 'SELECT s FROM ' . ListeningSessionEntity::class . ' s WHERE s.userId = :owner';
        $row = $this->rows($locked, ['owner' => $userId], lock: true)[0] ?? null;
        if ($row === null) {
            // Only an account's first operation finds no row. The insert tolerates a row that was
            // created outside the account lock, which a unit-of-work insert would not.
            $this->connection()->executeStatement(<<<'SQL'
                INSERT INTO listening_sessions(id,public_id,user_id,queue,current_track_index,position,playback_state,created_at,updated_at)
                VALUES(:id,:publicId,:owner,'[]',0,0,'stopped',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                ON CONFLICT(user_id) DO NOTHING
                SQL, ['id' => (new Uuid())->toString(), 'publicId' => (new PublicId())->toString(), 'owner' => $userId]);
            $row = $this->rows($locked, ['owner' => $userId], lock: true)[0] ?? null;
        }
        if ($row === null) {
            throw new ConflictException('Playback session is unavailable.');
        }
        return $this->hydrate($row);
    }

    public function saveSession(ListeningSession $aggregate): void
    {
        $session = $this->sessionRow($aggregate);
        $this->change(
            'UPDATE ' . ListeningSessionEntity::class . ' s SET s.revision = :revision, s.media = :media, s.queue = :queue,'
            . ' s.currentTrackIndex = :index, s.position = :position, s.playbackState = :desired, s.desiredState = :desired,'
            . ' s.observedState = :observed, s.selectedDestinationId = :destination, s.activeIncarnationId = :incarnation,'
            . ' s.updatedAt = CURRENT_TIMESTAMP(), s.lastUsedAt = CURRENT_TIMESTAMP() WHERE s.id = :id',
            ['revision' => $session['revision'], 'media' => $session['media'], 'queue' => $session['queue'], 'index' => $session['current_track_index'],
                'position' => $session['position'], 'desired' => $session['desired_state'], 'observed' => $session['observed_state'],
                'destination' => $session['selected_destination_id'], 'incarnation' => $session['active_incarnation_id'], 'id' => $session['id']],
            ['media' => Types::JSON, 'queue' => Types::JSON],
        );
    }

    public function retireConfirmedPausedBrowser(ListeningSession $session): bool
    {
        // The caller holds the account/session lock. All commands and observations use that lock.
        // This proof covers managed browser execution only; independent external routes need their own proof.
        // It stays one SQL statement: the proof is a single predicate over the session's whole command
        // ledger, which DQL cannot write and PHP could only evaluate by reading the ledger.
        // The acknowledgement must be the last thing that moved the session. A command settled as never
        // effective since then cannot have changed the runner, so it is no later command, and the
        // session may stand at the revision the last such settlement recorded instead of the one the
        // acknowledgement left. A superseded command still counts: its runner was shown it.
        // The acknowledgement is the runner's own answer that it is silent: to a pause, a quiesce or
        // a stop, or to a start whose command wanted the paused state, which the runner answers only
        // once it stands loaded and paused. A start that wanted playback proves nothing of the kind.
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
                        AND proof.acknowledged_at IS NOT NULL
                        AND ((proof.type='pause' AND proof.outcome='paused')
                            OR (proof.type='quiesce' AND proof.outcome='quiesced')
                            OR (proof.type='stop' AND proof.outcome='stopped')
                            OR (proof.type='start' AND proof.outcome='started' AND proof.payload->>'desiredState'='paused'))
                        AND :revision=coalesce((
                            SELECT max(settled.settled_revision) FROM playback_commands settled
                            WHERE settled.session_id=proof.session_id AND settled.outcome='never_effective'
                                AND (settled.revision>=proof.revision OR settled.created_at>proof.created_at)
                        ),proof.revision+1)
                        AND NOT EXISTS (
                            SELECT 1 FROM playback_commands later
                            WHERE later.session_id=proof.session_id AND later.id<>proof.id
                                AND later.outcome IS DISTINCT FROM 'never_effective'
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
                'revision' => $session->revision()]) !== false;
    }

    public function retireConfirmedStoppedBrowser(ListeningSession $session): bool
    {
        // The caller holds the account/session lock, and this covers managed browser execution only,
        // as for a confirmed paused browser, in the same SQL form. No acknowledged command is required as proof: a session
        // that never played has none, and while a stop is wanted and no command or transfer is
        // outstanding, nothing can have restarted the runner after its own stop report.
        if ($session->activeIncarnationId() === null || $session->desiredState() !== PlaybackState::Stopped
            || $session->observedState() !== ObservedPlaybackState::Stopped) {
            return false;
        }
        return $this->connection()->fetchOne(<<<'SQL'
            UPDATE playback_destination_incarnations i SET retired_at=coalesce(i.retired_at,CURRENT_TIMESTAMP)
            FROM playback_destinations d
            WHERE i.id=:incarnation AND i.user_id=:owner AND d.id=i.destination_id AND d.kind='browser'
                AND (i.expires_at<=CURRENT_TIMESTAMP OR i.retired_at IS NOT NULL)
                AND i.observed_state='stopped'
                AND NOT EXISTS (SELECT 1 FROM playback_commands pending
                    WHERE pending.session_id=:session AND pending.acknowledged_at IS NULL)
                AND NOT EXISTS (SELECT 1 FROM playback_transfers transfer
                    WHERE transfer.session_id=:session AND transfer.state NOT IN ('committed','failed'))
            RETURNING i.id
            SQL, ['incarnation' => $session->activeIncarnationId()->toString(),
                'owner' => $session->getUserId()->toString(), 'session' => $session->getId()->toString()]) !== false;
    }

    public function register(string $userId, array $input): array
    {
        $deviceId = Uuid::fromString($input['deviceId'])->toString();
        $endpointId = Uuid::fromString($input['endpointId'])->toString();
        // The device row carries the transaction's clock along, for the rows inserted below.
        $device = $this->rows(
            'SELECT d.name, CURRENT_TIMESTAMP() AS now FROM ' . DeviceEntity::class . ' d WHERE d.userId = :owner AND d.deviceId = :device',
            ['owner' => $userId, 'device' => $deviceId],
        )[0] ?? null;
        if ($device === null) {
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
        $now = new \DateTimeImmutable($device['now']);
        // Every writer of these rows holds the account lock, so a destination the update did not
        // find cannot appear before the insert.
        $tab = ['owner' => $userId, 'device' => $deviceId, 'endpoint' => $endpointId];
        $known = ' WHERE d.userId = :owner AND d.deviceId = :device AND d.endpointId = :endpoint';
        $renamed = $this->change(
            'UPDATE ' . PlaybackDestinationEntity::class . ' d SET d.name = :name, d.capabilities = :capabilities' . $known,
            $tab + ['name' => $name, 'capabilities' => $capabilities],
            ['capabilities' => Types::JSON],
        );
        if ($renamed === 0) {
            $publicId = new PublicId();
            $destination = ['id' => new Uuid(), 'publicId' => $publicId->toString()];
            $this->insert(new PlaybackDestinationEntity(
                $destination['id'],
                $publicId,
                Uuid::fromString($userId),
                Uuid::fromString($deviceId),
                Uuid::fromString($endpointId),
                $name,
                $capabilities,
                $now,
            ));
        } else {
            $destination = $this->rows('SELECT d.id, d.publicId FROM ' . PlaybackDestinationEntity::class . ' d' . $known, $tab)[0]
                ?? throw new ConflictException('Endpoint registration failed.');
        }
        $epoch = 1 + (int) $this->entityManager
            ->createQuery('SELECT COALESCE(MAX(i.epoch), 0) FROM ' . PlaybackDestinationIncarnationEntity::class . ' i WHERE i.destinationId = :destination')
            ->setParameters(['destination' => $destination['id']->toString()])
            ->getSingleScalarResult();
        // Earlier incarnations are retired before the new one exists, so it is not retired by its own registration.
        $this->change(
            'UPDATE ' . PlaybackDestinationIncarnationEntity::class . ' i SET i.retiredAt = CURRENT_TIMESTAMP() WHERE i.destinationId = :destination AND i.retiredAt IS NULL',
            ['destination' => $destination['id']->toString()],
        );
        $incarnation = new Uuid();
        $capability = bin2hex(random_bytes(32));
        // The recovery credential outlives the transaction by thirty days and is the one instant set from this process's clock.
        $recovery = $eligible
            ? ['credential' => bin2hex(random_bytes(32)), 'expires' => new \DateTimeImmutable('+30 days'), 'tab' => Uuid::fromString($tabId)]
            : null;
        $this->insert(new PlaybackDestinationIncarnationEntity(
            $incarnation,
            $destination['id'],
            Uuid::fromString($userId),
            $epoch,
            hash('sha256', $capability),
            $now->modify('+' . self::PRESENCE_LIFETIME . ' seconds'),
            $eligible,
            $recovery['tab'] ?? null,
            $recovery === null ? null : hash('sha256', $recovery['credential']),
            $recovery['expires'] ?? null,
        ));
        $result = ['destinationId' => $destination['publicId'], 'incarnationId' => $incarnation->toString(), 'epoch' => $epoch, 'capability' => $capability];
        if ($recovery !== null) {
            $result += ['recoveryCredential' => $recovery['credential'], 'recoveryExpiresAt' => $recovery['expires']->format(\DateTimeInterface::ATOM),
                'recoveryTabId' => $tabId];
        }
        return $result;
    }

    public function endpoint(string $userId, string $incarnationId, ?string $capability = null): array
    {
        $found = $this->rows(
            'SELECT i, d.publicId AS public_id, d.kind AS kind, d.capabilities AS capabilities, ' . self::AVAILABLE . ' AS available'
            . ' FROM ' . PlaybackDestinationIncarnationEntity::class . ' i JOIN ' . PlaybackDestinationEntity::class . ' d WITH d.id = i.destinationId'
            . ' WHERE i.id = :id AND i.userId = :owner',
            ['id' => Uuid::fromString($incarnationId)->toString(), 'owner' => $userId],
        )[0] ?? null;
        $row = $found === null ? null : self::columns($found[0])
            + ['public_id' => $found['public_id'], 'kind' => $found['kind'], 'capabilities' => $found['capabilities'], 'available' => $found['available']];
        if ($row === null || ($capability !== null && !hash_equals($row['capability_hash'], hash('sha256', $capability)))) {
            throw new ConflictException('Endpoint authority is invalid.');
        }
        // Retirement never implies silence, and the old runner may still acknowledge a stop.
        return $row;
    }

    public function localRecovery(string $owner, string $incarnationId, string $tabId, #[\SensitiveParameter] string $credential): array
    {
        $found = $this->rows(
            'SELECT i FROM ' . PlaybackDestinationIncarnationEntity::class . ' i WHERE i.id = :id AND i.userId = :owner AND i.tabId = :tab',
            ['id' => $incarnationId, 'owner' => $owner, 'tab' => $tabId],
            lock: true,
        )[0] ?? null;
        $row = $found === null ? null : self::columns($found);
        if ($row === null || !$row['local_recovery_eligible']
            || $row['recovery_revoked_at'] !== null || !is_string($row['recovery_credential_hash'])
            || !hash_equals($row['recovery_credential_hash'], hash('sha256', $credential))) {
            throw new ConflictException('Local recovery authority is invalid or expired.');
        }
        // The expiry is judged on the clock as it stands after this transaction waited for its locks.
        // DQL offers only the transaction's start time.
        $valid = $this->connection()->fetchOne('SELECT recovery_expires_at>clock_timestamp() FROM playback_destination_incarnations WHERE id=:id', ['id' => $incarnationId]);
        if (!$valid) {
            throw new ConflictException('Local recovery authority is invalid or expired.');
        }
        return $row;
    }

    public function completeLocalRecovery(string $incarnationId, array $result): void
    {
        $this->change(
            'UPDATE ' . PlaybackDestinationIncarnationEntity::class . " i SET i.retiredAt = COALESCE(i.retiredAt, CURRENT_TIMESTAMP()),"
            . " i.observedState = 'stopped', i.recoveryResult = :result WHERE i.id = :id",
            ['id' => $incarnationId, 'result' => $result],
            ['result' => Types::JSON],
        );
    }

    public function supersedeIncarnationCommands(string $incarnationId): void
    {
        $this->change(
            'UPDATE ' . PlaybackCommandEntity::class . " c SET c.outcome = 'superseded', c.acknowledgedAt = CURRENT_TIMESTAMP() WHERE c.incarnationId = :id AND c.acknowledgedAt IS NULL",
            ['id' => $incarnationId],
        );
    }

    public function sourceQuiesced(string $attemptId, string $sourceId): bool
    {
        return $this->rows(
            'SELECT c.id FROM ' . PlaybackCommandEntity::class . " c WHERE c.attemptId = :attempt AND c.incarnationId = :source AND c.type = 'quiesce' AND c.outcome = 'quiesced'",
            ['attempt' => $attemptId, 'source' => $sourceId],
            limit: 1,
        ) !== [];
    }

    public function currentTransfer(string $sessionId): ?array
    {
        $row = $this->rows(
            'SELECT t FROM ' . PlaybackTransferEntity::class . " t WHERE t.sessionId = :id AND t.state NOT IN ('committed', 'failed')",
            ['id' => $sessionId],
        )[0] ?? null;
        return $row === null ? null : self::columns($row);
    }

    public function saveTransfer(array $transfer): void
    {
        $progressed = $this->change(
            'UPDATE ' . PlaybackTransferEntity::class . ' t SET t.state = :state, t.position = :position, t.revision = :revision,'
            . ' t.expiresAt = ' . self::expiresAfter(self::COMMAND_LIFETIME) . ' WHERE t.id = :id',
            ['id' => $transfer['id'], 'state' => $transfer['state'], 'position' => $transfer['position'], 'revision' => $transfer['revision']],
        );
        if ($progressed > 0) {
            return;
        }
        // Only the holder of the account lock creates this session's transfers, so none can appear
        // between the update that found no row and the insert.
        $now = $this->transactionClock($transfer['session_id']);
        $this->insert(new PlaybackTransferEntity(
            Uuid::fromString($transfer['id']),
            Uuid::fromString($transfer['session_id']),
            Uuid::fromString($transfer['user_id']),
            Uuid::fromString($transfer['request_id']),
            $transfer['request_digest'],
            $transfer['source_incarnation_id'] === null ? null : Uuid::fromString($transfer['source_incarnation_id']),
            Uuid::fromString($transfer['target_incarnation_id']),
            $transfer['state'],
            $transfer['desired_state'],
            (float) $transfer['position'],
            (int) $transfer['revision'],
            $now->modify('+' . self::COMMAND_LIFETIME . ' seconds'),
            $now,
        ));
    }

    public function insertCommand(array $command): void
    {
        $addressed = $command['incarnation_id'] !== null;
        if ($addressed) {
            // A command replayed under the same key is the command already stored. The session row
            // carries the transaction's clock along with the answer. A retained command has no
            // incarnation, which the key never matches, so each one is stored.
            $known = $this->rows(
                'SELECT CURRENT_TIMESTAMP() AS now, (SELECT COUNT(replayed.id) FROM ' . PlaybackCommandEntity::class . ' replayed'
                . ' WHERE replayed.sessionId = s.id AND replayed.requestId = :request AND replayed.type = :type'
                . ' AND replayed.incarnationId = :incarnation AND replayed.revision = :revision) AS replays'
                . ' FROM ' . ListeningSessionEntity::class . ' s WHERE s.id = :session',
                ['session' => $command['session_id'], 'request' => $command['request_id'], 'type' => $command['type'],
                    'incarnation' => $command['incarnation_id'], 'revision' => $command['revision']],
            )[0] ?? throw new ConflictException('Playback session is unavailable.');
            if ((int) $known['replays'] > 0) {
                return;
            }
            $now = new \DateTimeImmutable($known['now']);
        } else {
            $now = $this->transactionClock($command['session_id']);
        }
        $this->insert(new PlaybackCommandEntity(
            Uuid::fromString($command['id']),
            Uuid::fromString($command['session_id']),
            Uuid::fromString($command['user_id']),
            Uuid::fromString($command['attempt_id']),
            Uuid::fromString($command['request_id']),
            $command['request_digest'],
            $addressed ? Uuid::fromString($command['incarnation_id']) : null,
            $command['epoch'],
            (int) $command['revision'],
            $command['type'],
            $command['payload'],
            $addressed ? null : 'retained',
            $addressed ? null : $now,
            $now->modify('+' . self::COMMAND_LIFETIME . ' seconds'),
            $now,
        ));
    }

    public function command(string $userId, string $commandId): array
    {
        $row = $this->rows(
            'SELECT c FROM ' . PlaybackCommandEntity::class . ' c WHERE c.id = :id AND c.userId = :owner',
            ['id' => Uuid::fromString($commandId)->toString(), 'owner' => $userId],
        )[0] ?? null;
        if ($row === null) {
            throw new ConflictException('Playback command is unavailable.');
        }
        return self::columns($row);
    }

    public function acknowledgeCommand(string $commandId, string $outcome, ?string $rejectionReason = null): void
    {
        // An answer is written once: a command that already has one keeps it.
        $this->change(
            'UPDATE ' . PlaybackCommandEntity::class . ' c SET c.outcome = :outcome, c.rejectionReason = :reason, c.acknowledgedAt = CURRENT_TIMESTAMP()'
            . ' WHERE c.id = :id AND c.acknowledgedAt IS NULL',
            ['outcome' => $outcome, 'reason' => $rejectionReason, 'id' => $commandId],
        );
    }

    public function requestedCommand(string $sessionId, string $requestId): ?array
    {
        $row = $this->rows(
            'SELECT c.id, c.requestId, c.outcome, c.rejectionReason FROM ' . PlaybackCommandEntity::class . ' c'
            . ' WHERE c.sessionId = :session AND c.requestId = :request ORDER BY c.createdAt, c.id',
            ['session' => $sessionId, 'request' => $requestId],
            limit: 1,
        )[0] ?? null;
        return $row === null ? null : self::columns($row);
    }

    public function requestedTransfer(string $sessionId, string $requestId): ?array
    {
        $transfer = $this->rows(
            'SELECT t.id, t.requestId, t.state FROM ' . PlaybackTransferEntity::class . ' t WHERE t.sessionId = :session AND t.requestId = :request',
            ['session' => $sessionId, 'request' => $requestId],
        )[0] ?? null;
        if ($transfer === null) {
            return null;
        }
        // The reason the attempt was first refused for, if any of its commands was.
        $rejected = $this->rows(
            'SELECT c.rejectionReason FROM ' . PlaybackCommandEntity::class . ' c'
            . " WHERE c.sessionId = :session AND c.attemptId = :attempt AND c.outcome = 'rejected' ORDER BY c.createdAt, c.id",
            ['session' => $sessionId, 'attempt' => $transfer['id']->toString()],
            limit: 1,
        )[0] ?? null;
        return self::columns($transfer) + ['rejection_reason' => $rejected['rejectionReason'] ?? null];
    }

    public function cancelCommands(string $attemptId): void
    {
        $this->change(
            'UPDATE ' . PlaybackCommandEntity::class . " c SET c.outcome = 'superseded', c.acknowledgedAt = CURRENT_TIMESTAMP() WHERE c.attemptId = :attempt AND c.acknowledgedAt IS NULL",
            ['attempt' => $attemptId],
        );
    }

    public function settleNeverEffective(string $attemptId, int $sessionRevision): void
    {
        $this->change(
            'UPDATE ' . PlaybackCommandEntity::class . " c SET c.outcome = 'never_effective', c.settledRevision = :revision, c.acknowledgedAt = CURRENT_TIMESTAMP()"
            . ' WHERE c.attemptId = :attempt AND c.acknowledgedAt IS NULL',
            ['attempt' => $attemptId, 'revision' => $sessionRevision],
        );
    }

    public function attemptDisclosed(string $attemptId, string $type): bool
    {
        return $this->rows(
            'SELECT c.id FROM ' . PlaybackCommandEntity::class . ' c WHERE c.attemptId = :attempt AND c.type = :type AND c.disclosedAt IS NOT NULL',
            ['attempt' => $attemptId, 'type' => $type],
            limit: 1,
        ) !== [];
    }

    public function markRunnerUnknown(string $incarnationId): void
    {
        $this->change('UPDATE ' . PlaybackDestinationIncarnationEntity::class . " i SET i.observedState = 'unknown' WHERE i.id = :id", ['id' => $incarnationId]);
    }

    public function observe(string $incarnationId, string $state, float $position, ?float $volume = null): void
    {
        $this->change(
            'UPDATE ' . PlaybackDestinationIncarnationEntity::class . ' i SET i.observedState = :state, i.position = :position,'
            . ' i.observedVolume = COALESCE(:volume, i.observedVolume), i.expiresAt = ' . self::expiresAfter(self::PRESENCE_LIFETIME) . ' WHERE i.id = :id',
            ['state' => $state, 'position' => $position, 'volume' => $volume, 'id' => $incarnationId],
            ['volume' => Types::FLOAT],
        );
    }

    public function observeVolume(string $incarnationId, float $volume): void
    {
        $this->change(
            'UPDATE ' . PlaybackDestinationIncarnationEntity::class . ' i SET i.observedVolume = :volume, i.expiresAt = ' . self::expiresAfter(self::PRESENCE_LIFETIME) . ' WHERE i.id = :id',
            ['volume' => $volume, 'id' => $incarnationId],
        );
    }

    public function snapshot(ListeningSession $aggregate, ?string $incarnationId): array
    {
        $session = $this->sessionRow($aggregate);
        // Each destination with its latest incarnation, which is the one with the highest epoch: the
        // pair of destination and epoch is unique. A destination without an incarnation still appears.
        // The row's own key travels along to resolve the session's two references below, and is not shown.
        $destinations = $this->rows(
            'SELECT d.id AS destinationKey, d.publicId AS destinationId, d.name AS name, d.kind AS kind, d.capabilities AS capabilities,'
            . ' i.id AS incarnationId, i.epoch AS epoch, i.observedState AS observedState, i.position AS position, i.observedVolume AS observedVolume,'
            . ' ' . self::AVAILABLE . ' AS available'
            . ' FROM ' . PlaybackDestinationEntity::class . ' d LEFT JOIN ' . PlaybackDestinationIncarnationEntity::class . ' i'
            . ' WITH i.destinationId = d.id AND i.epoch = (SELECT MAX(latest.epoch) FROM ' . PlaybackDestinationIncarnationEntity::class . ' latest WHERE latest.destinationId = d.id)'
            . ' WHERE d.userId = :owner ORDER BY d.createdAt, i.epoch',
            ['owner' => $session['user_id']],
        );
        $commands = [];
        if ($incarnationId !== null && $this->connection()->getTransactionNestingLevel() <= 1) {
            // This is the only place a pending command leaves the server. Recording it in the transaction
            // that builds the snapshot means a command without the mark was never shown to its runner.
            // Nothing can add or answer a command between the read and the mark: both run under the
            // session lock. A resting session and one whose commands were all shown before pay no mark.
            $pending = ' WHERE c.sessionId = :session AND c.incarnationId = :incarnation AND c.acknowledgedAt IS NULL';
            $addressee = ['session' => $session['id'], 'incarnation' => $incarnationId];
            $commands = $this->rows(
                'SELECT c.id AS id, c.attemptId AS attemptId, c.revision AS revision, c.incarnationId AS incarnationId, c.epoch AS epoch,'
                . ' c.type AS type, c.payload AS payload, c.expiresAt AS expiresAt, c.disclosedAt AS disclosedAt'
                . ' FROM ' . PlaybackCommandEntity::class . ' c' . $pending . ' ORDER BY c.createdAt, c.id',
                $addressee,
            );
            if (array_any($commands, static fn(array $command): bool => $command['disclosedAt'] === null)) {
                $this->change(
                    'UPDATE ' . PlaybackCommandEntity::class . ' c SET c.disclosedAt = CURRENT_TIMESTAMP()' . $pending . ' AND c.disclosedAt IS NULL',
                    $addressee,
                );
            }
        }
        // The selected destination is one of the owner's, all of which were just read.
        $selected = $session['selected_destination_id'] === null ? null : array_find(
            $destinations,
            static fn(array $destination): bool => $destination['destinationKey']->toString() === $session['selected_destination_id'],
        )['destinationId'] ?? false;
        // So is the active incarnation's destination, unless a later registration of the same tab
        // replaced the incarnation while it still holds the session. Only then is it looked up.
        $active = $session['active_incarnation_id'] === null ? null : array_find(
            $destinations,
            static fn(array $destination): bool => $destination['incarnationId']?->toString() === $session['active_incarnation_id'],
        )['destinationId'] ?? $this->rows(
            'SELECT d.publicId FROM ' . PlaybackDestinationIncarnationEntity::class . ' i JOIN ' . PlaybackDestinationEntity::class . ' d WITH d.id = i.destinationId WHERE i.id = :id',
            ['id' => $session['active_incarnation_id']],
        )[0]['publicId'] ?? false;
        $transfer = $this->currentTransfer($session['id']);
        return ['schemaVersion' => 1, 'publicId' => $session['public_id'], 'revision' => (int) $session['revision'],
            'media' => $session['media'], 'queue' => $session['queue'], 'currentTrackIndex' => (int) $session['current_track_index'],
            'position' => (float) $session['position'], 'playbackState' => $session['desired_state'], 'desiredState' => $session['desired_state'],
            'observedState' => $session['observed_state'], 'selectedDestinationId' => $selected, 'activeDestinationId' => $active,
            'activeIncarnationId' => $session['active_incarnation_id'],
            'transfer' => $transfer === null ? null : ['attemptId' => $transfer['id'], 'state' => $transfer['state'],
                'sourceIncarnationId' => $transfer['source_incarnation_id'], 'targetIncarnationId' => $transfer['target_incarnation_id'],
                'position' => (float) $transfer['position'], 'desiredState' => $transfer['desired_state']],
            'destinations' => array_map(
                static fn(array $destination): array => [
                    ...array_diff_key($destination, ['destinationKey' => true]),
                    'incarnationId' => $destination['incarnationId']?->toString(),
                ],
                $destinations,
            ),
            'commands' => array_map(static fn(array $command): array => [
                'id' => $command['id']->toString(), 'attemptId' => $command['attemptId']->toString(), 'revision' => $command['revision'],
                'incarnationId' => $command['incarnationId']->toString(), 'epoch' => $command['epoch'], 'type' => $command['type'],
                'payload' => $command['payload'], 'expiresAt' => $command['expiresAt']->format(\DateTimeInterface::RFC3339_EXTENDED),
            ], $commands)];
    }

    public function hasPendingControl(string $sessionId): bool
    {
        return $this->pendingControl($sessionId) !== null;
    }

    public function pendingControl(string $sessionId): ?array
    {
        $command = $this->rows(
            'SELECT c FROM ' . PlaybackCommandEntity::class . ' c'
            . ' WHERE c.sessionId = :session AND c.acknowledgedAt IS NULL AND c.type IN (' . self::CONTROL_TYPES . ') ORDER BY c.createdAt, c.id',
            ['session' => $sessionId],
            limit: 1,
        )[0] ?? null;
        return $command === null ? null : self::columns($command);
    }

    public function rememberRequest(string $sessionId, string $requestId, string $digest): bool
    {
        // A transfer's commands carry its request identity and digest, and so do the stops issued for
        // an expired control, so whichever row is found first gives the same answer.
        $previous = null;
        foreach ([PlaybackTransferEntity::class, PlaybackCommandEntity::class] as $remembered) {
            $previous ??= $this->rows(
                'SELECT r.requestDigest FROM ' . $remembered . ' r WHERE r.sessionId = :session AND r.requestId = :request',
                ['session' => $sessionId, 'request' => $requestId],
                limit: 1,
            )[0]['requestDigest'] ?? null;
        }
        if ($previous === null) {
            return false;
        }
        if (!hash_equals($previous, $digest)) {
            throw new ConflictException('The playback request identity has already been used for another payload.');
        }
        return true;
    }

    /**
     * @param array<string,mixed> $parameters
     * @return list<array<array-key,mixed>> Rows as arrays; the unit of work never sees them.
     */
    private function rows(string $dql, array $parameters, ?int $limit = null, bool $lock = false): array
    {
        $query = $this->entityManager->createQuery($dql)->setParameters($parameters)->setMaxResults($limit);
        // A row lock lasts until its transaction ends, and outside one it is released as soon as it is
        // taken. Doctrine refuses a lock mode there, so the read then asks for none.
        if ($lock && $this->connection()->isTransactionActive()) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        return $query->getArrayResult();
    }

    /**
     * One guarded statement. The predicate is the fence; no entity is loaded.
     *
     * @param array<string,mixed> $parameters
     * @param array<string,string> $types The mapping type of each parameter that is not a plain scalar.
     * @return int The number of rows the statement changed.
     */
    private function change(string $dql, array $parameters, array $types = []): int
    {
        $query = $this->entityManager->createQuery($dql);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value, $types[$name] ?? null);
        }
        return (int) $query->execute();
    }

    /**
     * Writes the new row before the method returns, so a later read of the operation finds it, and
     * lets go of the entity: these rows change only through guarded statements.
     */
    private function insert(object $row): void
    {
        $this->entityManager->persist($row);
        $this->entityManager->flush();
        $this->entityManager->detach($row);
    }

    /** The end of a lifetime an update starts, as its statement writes it. An insert counts the same seconds from the transaction's clock. */
    private static function expiresAfter(int $seconds): string
    {
        return "DATE_ADD(CURRENT_TIMESTAMP(), {$seconds}, 'second')";
    }

    /** The instant the current transaction began, which is what CURRENT_TIMESTAMP stamps in every statement of it. */
    private function transactionClock(string $sessionId): \DateTimeImmutable
    {
        $now = $this->rows('SELECT CURRENT_TIMESTAMP() AS now FROM ' . ListeningSessionEntity::class . ' s WHERE s.id = :session', ['session' => $sessionId])[0]['now']
            ?? throw new ConflictException('Playback session is unavailable.');
        return new \DateTimeImmutable($now);
    }

    /**
     * A row as the coordinator reads it: column names as keys, identifiers and instants as strings.
     * Numbers, booleans and decoded JSON keep the types the mapping gives them.
     *
     * @param array<string,mixed> $fields A hydrated row keyed by field name.
     * @return array<string,mixed>
     */
    private static function columns(array $fields): array
    {
        /** @var array<string,string> $names The mapped field names are a fixed, small set, so each is converted once per worker. */
        static $names = [];
        $row = [];
        foreach ($fields as $field => $value) {
            $row[$names[$field] ??= strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field))] = match (true) {
                $value instanceof Uuid => $value->toString(),
                $value instanceof \DateTimeImmutable => $value->format(InstantType::WRITE_FORMAT),
                default => $value,
            };
        }
        return $row;
    }

    /** @param array<string,mixed> $row A hydrated session row keyed by field name. */
    private function hydrate(array $row): ListeningSession
    {
        return ListeningSession::reconstitute(new ListeningSessionState(
            id: $row['id'],
            publicId: PublicId::fromString($row['publicId']),
            userId: $row['userId'],
            activeDeviceId: $row['activeDeviceId'],
            queue: $row['queue'],
            currentTrackIndex: $row['currentTrackIndex'],
            position: $row['position'],
            playbackState: $row['playbackState'],
            createdAt: $row['createdAt'],
            updatedAt: $row['updatedAt'],
            lastUsedAt: $row['lastUsedAt'],
            revision: $row['revision'],
            media: $row['media'] === null ? null : new PlaybackMedia($row['media']['kind'], PublicId::fromString($row['media']['publicId'])),
            desiredState: PlaybackState::from($row['desiredState']),
            observedState: ObservedPlaybackState::from($row['observedState']),
            selectedDestinationId: $row['selectedDestinationId'],
            activeIncarnationId: $row['activeIncarnationId'],
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
}
