<?php

declare(strict_types=1);

use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once __DIR__ . '/../Messaging/MessageCodecFactory.php';

$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments)) {
    throw new RuntimeException('Invalid isolated worker command arguments.');
}
[$script, $mode, $namespace, $bootId] = $arguments + [null, null, null, null];
$allowedModes = [
    'prepare-consumer-ack',
    'hold-consumer-ack',
    'release-consumer-ack',
    'verify-consumer-ack-blocked',
    'verify-consumer-ack-contained',
    'verify-consumer-ack-recovered',
    'age-consumer-ack-pending',
    'kill-consumer-before-ack',
    'hold-relay-delivery-ack',
    'release-relay-delivery-ack',
    'verify-delivery-ack-blocked',
    'verify-delivery-ack-contained',
    'verify-delivery-ack-recovered',
    'kill-relay-after-send',
    'hold-relay-projection',
    'release-relay-projection',
    'verify-projection-blocked',
    'verify-projection-contained',
    'kill-relay-projecting',
    'hold-relay-claim',
    'release-relay-claim',
    'verify-claim-blocked',
    'verify-claim-contained',
    'verify-claim-recovered',
    'kill-relay-claimed',
    'ready',
    'kill-consumer',
    'kill-scheduler',
    'reserved',
    'expire',
    'seed-outbox',
    'verify-outbox',
    'replay-outbox',
    'seed-scheduler',
    'verify-scheduler',
    'replay-scheduler',
];

if (
    !in_array($mode, $allowedModes, true)
    || !is_string($namespace)
    || !is_string($bootId)
) {
    throw new RuntimeException('Invalid isolated worker check request.');
}
App\Shared\Infrastructure\Worker\DeploymentLease::validateIdentity($namespace, $bootId);
$url = getenv('DATABASE_URL');
if (!$url) {
    throw new RuntimeException('Disposable DATABASE_URL is required.');
}
$db = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
if ($mode === 'prepare-consumer-ack') {
    $db->executeStatement(<<<'SQL'
        CREATE TABLE worker_command_consumer_ack_gate (
            id boolean PRIMARY KEY CHECK (id),
            armed boolean NOT NULL DEFAULT false,
            occurrence_id uuid,
            message_id text,
            arrivals integer NOT NULL DEFAULT 0
        )
        SQL);
    $db->executeStatement('INSERT INTO worker_command_consumer_ack_gate (id) VALUES (true)');
    exit(0);
}
if ($mode === 'hold-consumer-ack') {
    $db->executeQuery('SELECT pg_advisory_lock(87365024)')->free();
    $db->executeStatement('UPDATE worker_command_consumer_ack_gate SET armed = true WHERE id = true');
    echo "Consumer acknowledgement gate armed.\n";
    flush();
    $db->executeQuery('SELECT pg_sleep(120)')->free();
    throw new RuntimeException('Consumer acknowledgement gate exceeded its bounded lifetime.');
}
if ($mode === 'release-consumer-ack') {
    $db->executeStatement('UPDATE worker_command_consumer_ack_gate SET armed = false WHERE id = true');
    exit(0);
}
$consumerAckModes = [
    'verify-consumer-ack-blocked',
    'verify-consumer-ack-contained',
    'verify-consumer-ack-recovered',
    'age-consumer-ack-pending',
    'kill-consumer-before-ack',
];

if (in_array($mode, $consumerAckModes, true)) {
    $execution = $db->fetchAssociative(<<<'SQL'
        SELECT o.id, e.attempt_id, e.returned_at, e.deployment_namespace,
            e.deployment_boot_id, e.deployment_epoch, j.run_count, j.last_result
        FROM scheduler_occurrences o
        JOIN scheduler_occurrence_executions e ON e.occurrence_id = o.id
        JOIN scheduled_jobs j ON j.id = o.job_id
        WHERE o.origin = 'manual' AND o.command = 'app:transcode:cache-sweep'
            AND o.dispatched_at IS NOT NULL AND j.last_error IS NULL
        SQL);
    $gate = $db->fetchAssociative('SELECT occurrence_id, message_id, arrivals FROM worker_command_consumer_ack_gate WHERE id = true');
    if (
        $execution === false
        || $gate === false
        || $execution['returned_at'] === null
        || $execution['deployment_namespace'] !== $namespace
        || $execution['deployment_boot_id'] !== $bootId
        || (int) $execution['deployment_epoch'] !== 1
        || (int) $execution['run_count'] !== 1
        || !str_contains($execution['last_result'] ?? '', 'DRY RUN')
        || $gate['occurrence_id'] !== $execution['id']
        || (int) $db->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions') !== 1
    ) {
        throw new RuntimeException(
            'Real scheduled effects and one returned execution receipt must be committed before Redis ACK.',
        );
    }
    $redis = new Redis();
    $redis->connect('redis', 6379, 1.0);
    $redis->auth(['default', 'test-only']);
    $pending = $redis->xPending('scheduler_occurrences', 'baander', '-', '+', 2);
    $waiting = (int) $db->fetchOne(<<<'SQL'
        SELECT count(*) FROM pg_locks
        WHERE locktype = 'advisory' AND classid = 0 AND objid = 87365024 AND NOT granted
        SQL);
    if ($mode === 'verify-consumer-ack-recovered') {
        $snapshot = json_decode($arguments[4] ?? '', true, 512, JSON_THROW_ON_ERROR);
        if (
            $snapshot['execution'] !== $execution
            || $snapshot['messageId'] !== $gate['message_id']
            || (int) $gate['arrivals'] !== 2
            || $waiting !== 0
            || $pending !== []
            || $redis->xLen('scheduler_occurrences') !== 0
            || $redis->zCard('scheduler_occurrences__queue') !== 0
            || $redis->xLen('failed_messages') !== 0
            || $redis->zCard('failed_messages__queue') !== 0
        ) {
            throw new RuntimeException(
                'The original pending Redis entry must replay and ACK with no second execution or changed returned receipt.',
            );
        }
        echo "Original Redis entry redelivered and acknowledged; committed receipt and run_count stayed unchanged.\n";
        exit(0);
    }
    if (
        !is_array($pending)
        || count($pending) !== 1
        || $pending[0][0] !== $gate['message_id']
        || (int) $gate['arrivals'] !== 1
        || $redis->xLen('scheduler_occurrences') !== 1
    ) {
        throw new RuntimeException(
            'One original Redis entry must remain pending after the handler committed exactly one execution.',
        );
    }
    $snapshot = ['execution' => $execution, 'messageId' => $pending[0][0], 'consumer' => $pending[0][1]];
    if (in_array($mode, ['verify-consumer-ack-contained', 'age-consumer-ack-pending'], true)) {
        $original = json_decode($arguments[4] ?? '', true, 512, JSON_THROW_ON_ERROR);
        if ($waiting !== 0 || $original !== $snapshot) {
            throw new RuntimeException(
                'Consumer containment must preserve the original pending entry and committed execution receipt.',
            );
        }
        if ($mode === 'age-consumer-ack-pending') {
            // Advance only this disposable pending entry's idle age. The real
            // consumer still uses the production one-hour redelivery threshold.
            $claimed = $redis->rawCommand(
                'XCLAIM', 'scheduler_occurrences', 'baander', $pending[0][1],
                '0', $pending[0][0], 'IDLE', '3600001', 'JUSTID',
            );
            if ($claimed !== [$pending[0][0]]) {
                throw new RuntimeException(
                    'Could not age the exact pending fixture entry for bounded production redelivery.',
                );
            }
            echo "Aged only the original disposable pending entry past the production redelivery threshold.\n";
        } else {
            echo "Committed handler effects and original unacknowledged Redis entry survived supervised consumer SIGKILL.\n";
        }
        exit(0);
    }
    if ($waiting !== 1) {
        throw new RuntimeException('Consumer has not reached WorkerMessageHandledEvent before Redis XACK.');
    }
    if ($mode === 'verify-consumer-ack-blocked') {
        echo json_encode($snapshot, JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
}

// This gate belongs only to the disposable database. The initial claim commits
// before the next UPDATE (renewLease) blocks, so SIGKILL cannot roll the claim back.
if ($mode === 'hold-relay-claim') {
    $db->executeQuery('SELECT pg_advisory_lock(87365021)')->free();
    $db->executeStatement(<<<'SQL'
        CREATE FUNCTION worker_command_claim_gate() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF OLD.lease_token IS NOT NULL AND NEW.lease_token = OLD.lease_token AND NEW.relayed_at IS NULL THEN
                PERFORM pg_advisory_xact_lock(87365021);
            END IF;
            RETURN NEW;
        END;
        $$
        SQL);
    $db->executeStatement('CREATE TRIGGER worker_command_claim_gate BEFORE UPDATE ON domain_event_outbox FOR EACH ROW EXECUTE FUNCTION worker_command_claim_gate()');
    echo "Claim gate armed.\n";
    flush();
    $db->executeQuery('SELECT pg_sleep(120)')->free();
    throw new RuntimeException('Claim gate exceeded its bounded lifetime.');
}
if ($mode === 'release-relay-claim') {
    $db->executeStatement('DROP TRIGGER worker_command_claim_gate ON domain_event_outbox');
    $db->executeStatement('DROP FUNCTION worker_command_claim_gate()');
    echo "Disposable claim gate removed.\n";
    exit(0);
}

// Redis accepts the message before markRelayed updates the delivery intent.
// Freeze only that acknowledgement, leaving accepted transport work durable.
if ($mode === 'hold-relay-delivery-ack') {
    $db->executeQuery('SELECT pg_advisory_lock(87365023)')->free();
    $db->executeStatement(<<<'SQL'
        CREATE FUNCTION worker_command_delivery_ack_gate() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF OLD.relayed_at IS NULL AND NEW.relayed_at IS NOT NULL THEN
                PERFORM pg_advisory_xact_lock(87365023);
            END IF;
            RETURN NEW;
        END;
        $$
        SQL);
    $db->executeStatement(<<<'SQL'
        CREATE TRIGGER worker_command_delivery_ack_gate
        BEFORE UPDATE ON domain_event_outbox_delivery
        FOR EACH ROW EXECUTE FUNCTION worker_command_delivery_ack_gate()
        SQL);
    echo "Delivery acknowledgement gate armed.\n";
    flush();
    $db->executeQuery('SELECT pg_sleep(120)')->free();
    throw new RuntimeException('Delivery acknowledgement gate exceeded its bounded lifetime.');
}
if ($mode === 'release-relay-delivery-ack') {
    $db->executeStatement('DROP TRIGGER worker_command_delivery_ack_gate ON domain_event_outbox_delivery');
    $db->executeStatement('DROP FUNCTION worker_command_delivery_ack_gate()');
    echo "Disposable delivery acknowledgement gate removed.\n";
    exit(0);
}
$deliveryAckModes = [
    'verify-delivery-ack-blocked',
    'verify-delivery-ack-contained',
    'verify-delivery-ack-recovered',
    'kill-relay-after-send',
];

if (in_array($mode, $deliveryAckModes, true)) {
    $deliveryClaims = $db->fetchAllAssociative(<<<'SQL'
        SELECT id, lease_token, lease_until FROM domain_event_outbox_delivery
        WHERE relayed_at IS NULL AND lease_token IS NOT NULL AND lease_until > clock_timestamp()
            AND attempts = 0 AND next_attempt_at IS NULL AND dead_lettered_at IS NULL
        ORDER BY id
        SQL);
    $redis = new Redis();
    $redis->connect('redis', 6379, 1.0);
    $redis->auth(['default', 'test-only']);
    $stream = $redis->xInfo('STREAM', 'messages');
    $entriesAdded = is_array($stream) ? ($stream['entries-added'] ?? null) : null;
    if ($mode === 'verify-delivery-ack-recovered') {
        $originalClaims = json_decode($arguments[4] ?? '', true, 512, JSON_THROW_ON_ERROR);
        if (
            !is_array($originalClaims)
            || count($originalClaims) !== 2
            || $entriesAdded !== 3
        ) {
            throw new RuntimeException(
                'Recovery must accept three Redis envelopes for two intents, including the uncertain-send replay.',
            );
        }
        foreach ($originalClaims as $claim) {
            $recovered = (int) $db->fetchOne(<<<'SQL'
                SELECT count(*) FROM domain_event_outbox_delivery
                WHERE id = :id AND relayed_at >= CAST(:expiry AS timestamptz)
                    AND lease_token IS NULL AND lease_until IS NULL
                    AND attempts = 0 AND dead_lettered_at IS NULL
                SQL, ['id' => $claim['id'], 'expiry' => $claim['lease_until']]);
            if ($recovered !== 1) {
                throw new RuntimeException(
                    'Every interrupted delivery intent must recover after its unchanged lease naturally expires.',
                );
            }
        }
        echo "Recovered two intents after natural expiry; Redis accepted the uncertain handoff again (three envelopes total).\n";
        exit(0);
    }
    if (
        count($deliveryClaims) !== 2
        || $entriesAdded !== 1
        || (int) $db->fetchOne('SELECT count(*) FROM notifications') !== 1
        || (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox_receipt') !== 1
        || (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox WHERE relayed_at IS NOT NULL') !== 1
    ) {
        throw new RuntimeException(
            'One Redis handoff must be accepted while two claimed intents remain unacknowledged and projections stay committed.',
        );
    }
    $acknowledging = (int) $db->fetchOne(<<<'SQL'
        SELECT count(*) FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid
        WHERE l.locktype = 'advisory' AND l.classid = 0 AND l.objid = 87365023
            AND NOT l.granted AND a.query LIKE 'UPDATE domain_event_outbox_delivery SET relayed_at%'
        SQL);
    if ($mode === 'verify-delivery-ack-contained') {
        $originalClaims = json_decode($arguments[4] ?? '', true, 512, JSON_THROW_ON_ERROR);
        if ($acknowledging !== 0 || $originalClaims !== $deliveryClaims) {
            throw new RuntimeException('Contained relay must preserve both delivery leases and the accepted Redis handoff.');
        }
        echo "Accepted Redis handoff survived relay containment; both original intent leases remain unchanged.\n";
        exit(0);
    }
    if ($acknowledging !== 1) {
        throw new RuntimeException('Relay has not reached the post-send delivery acknowledgement boundary.');
    }
    if ($mode === 'verify-delivery-ack-blocked') {
        echo json_encode($deliveryClaims, JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
}

// Block the ORM flush inside the receipt/projection transaction. A killed relay
// must roll back both the uncommitted receipt and every projection effect.
if ($mode === 'hold-relay-projection') {
    $db->executeQuery('SELECT pg_advisory_lock(87365022)')->free();
    $db->executeStatement(<<<'SQL'
        CREATE FUNCTION worker_command_projection_gate() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            PERFORM pg_advisory_xact_lock(87365022);
            RETURN NEW;
        END;
        $$
        SQL);
    $db->executeStatement(<<<'SQL'
        CREATE TRIGGER worker_command_projection_gate
        BEFORE INSERT ON notifications
        FOR EACH ROW EXECUTE FUNCTION worker_command_projection_gate()
        SQL);
    echo "Projection gate armed.\n";
    flush();
    $db->executeQuery('SELECT pg_sleep(120)')->free();
    throw new RuntimeException('Projection gate exceeded its bounded lifetime.');
}
if ($mode === 'release-relay-projection') {
    $db->executeStatement('DROP TRIGGER worker_command_projection_gate ON notifications');
    $db->executeStatement('DROP FUNCTION worker_command_projection_gate()');
    echo "Disposable projection gate removed.\n";
    exit(0);
}
$projectionModes = [
    'verify-projection-blocked',
    'verify-projection-contained',
    'kill-relay-projecting',
];

if (in_array($mode, $projectionModes, true)) {
    $projectionClaim = $db->fetchAssociative(<<<'SQL'
        SELECT id, lease_token, lease_until FROM domain_event_outbox
        WHERE event_name = 'user.registered' AND payload->>'email' = :email
            AND lease_token IS NOT NULL AND lease_until > clock_timestamp()
            AND relayed_at IS NULL AND attempts = 0 AND next_attempt_at IS NULL AND dead_lettered_at IS NULL
        SQL, ['email' => 'worker-command@baander.app']);
    if (
        $projectionClaim === false
        || (int) $db->fetchOne('SELECT count(*) FROM notifications') !== 0
        || (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox_receipt') !== 0
        || (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox_delivery') !== 0
    ) {
        throw new RuntimeException(
            'Projection claim must remain committed with zero visible receipt, projection, delivery or failure effects.',
        );
    }
    $projecting = (int) $db->fetchOne(<<<'SQL'
        SELECT count(*) FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid
        WHERE l.locktype = 'advisory' AND l.classid = 0 AND l.objid = 87365022
            AND NOT l.granted AND a.xact_start IS NOT NULL
            AND a.query ILIKE '%INSERT INTO notifications%'
        SQL);
    if ($mode === 'verify-projection-contained') {
        if (
            $projecting !== 0
            || ($arguments[4] ?? null) !== $projectionClaim['lease_token']
            || ($arguments[5] ?? null) !== $projectionClaim['lease_until']
        ) {
            throw new RuntimeException(
                'Contained projection relay must roll back its transaction and preserve the unchanged original claim.',
            );
        }
        echo "Killed projection transaction rolled back; unchanged lease and zero durable effects remain.\n";
        exit(0);
    }
    if ($projecting !== 1) {
        throw new RuntimeException('Relay has not reached notification flush inside its receipt/projection transaction.');
    }
    if ($mode === 'verify-projection-blocked') {
        echo json_encode($projectionClaim, JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
}

$claimedEvent = null;
if (in_array($mode, ['verify-claim-blocked', 'verify-claim-contained', 'kill-relay-claimed'], true)) {
    $claimedEvent = $db->fetchAssociative(<<<'SQL'
        SELECT id, lease_token, lease_until FROM domain_event_outbox
        WHERE event_name = 'user.registered' AND payload->>'email' = :email
            AND lease_token IS NOT NULL AND lease_until > clock_timestamp()
            AND relayed_at IS NULL AND attempts = 0 AND next_attempt_at IS NULL AND dead_lettered_at IS NULL
        SQL, ['email' => 'worker-command@baander.app']);
    if ($claimedEvent === false || (int) $db->fetchOne('SELECT count(*) FROM notifications') !== 0
        || (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox_receipt') !== 0
        || (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox_delivery') !== 0) {
        throw new RuntimeException('Claim must be committed and unexpired with zero projection, receipt, delivery or failure effects.');
    }
    $blocked = (int) $db->fetchOne(<<<'SQL'
        SELECT count(*) FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid
        WHERE l.locktype = 'advisory' AND l.classid = 0 AND l.objid = 87365021
            AND NOT l.granted AND a.query LIKE 'UPDATE domain_event_outbox SET lease_until%'
        SQL);
    if ($mode === 'verify-claim-contained') {
        if ($blocked !== 0 || ($arguments[4] ?? null) !== $claimedEvent['lease_token']
            || ($arguments[5] ?? null) !== $claimedEvent['lease_until']) {
            throw new RuntimeException('Contained relay must leave the same committed claim with no blocked backend: ' . json_encode(['blocked' => $blocked, 'sameToken' => ($arguments[4] ?? null) === $claimedEvent['lease_token'], 'sameExpiry' => ($arguments[5] ?? null) === $claimedEvent['lease_until']], JSON_THROW_ON_ERROR));
        }
        echo "Contained relay left its original unexpired claim and zero effects.\n";
        exit(0);
    }
    if ($blocked !== 1) {
        throw new RuntimeException('Relay has not reached the committed-claim renewal boundary.');
    }
    if ($mode === 'verify-claim-blocked') {
        echo json_encode($claimedEvent, JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
}
if ($mode === 'verify-claim-recovered') {
    $originalExpiry = $arguments[4] ?? null;
    if (!is_string($originalExpiry) || $originalExpiry === ''
        || (int) $db->fetchOne(<<<'SQL'
            SELECT count(*) FROM domain_event_outbox_receipt r
            JOIN domain_event_outbox o ON o.id = r.outbox_id
            WHERE o.event_name = 'user.registered' AND o.payload->>'email' = :email
                AND o.relayed_at IS NOT NULL AND o.lease_token IS NULL AND o.lease_until IS NULL
                AND r.consumer = 'notifications.v1' AND r.processed_at >= CAST(:originalExpiry AS timestamptz)
            SQL, ['email' => 'worker-command@baander.app', 'originalExpiry' => $originalExpiry]) !== 1) {
        throw new RuntimeException('The single projection receipt must be committed after the original claim naturally expires.');
    }
    echo "Projection receipt committed after the unchanged original claim expiry.\n";
    exit(0);
}
if ($mode === 'seed-scheduler') {
    $job = App\Shared\Domain\Model\Uuid::v7();
    $request = App\Shared\Domain\Model\Uuid::v7();
    $db->executeStatement(<<<'SQL'
        INSERT INTO scheduled_jobs (id, revision, name, expression, job_type, command, status, parameters, created_at, updated_at, run_count)
        VALUES (:id, :revision, 'Worker command scheduler fixture', '* * * * *', 'console', 'app:transcode:cache-sweep', 'paused', '{"dry-run":true}', clock_timestamp(), clock_timestamp(), 0)
        SQL, ['id' => $job->toString(), 'revision' => App\Shared\Domain\Model\Uuid::v7()->toString()]);
    if ((new App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerManualOccurrenceRecorder($db))->record($job, $request) === null
        || (int) $db->fetchOne('SELECT count(*) FROM scheduler_occurrences WHERE dispatched_at IS NOT NULL') !== 0
        || (int) $db->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions') !== 0) {
        throw new RuntimeException('Manual request must remain durable and undispatched before lease admission.');
    }
    echo "Real manual occurrence captured before worker lease admission.\n";
    exit(0);
}
if ($mode === 'verify-scheduler' || $mode === 'replay-scheduler') {
    $occurrence = $db->fetchAssociative(<<<'SQL'
        SELECT o.id, o.dispatched_at, e.returned_at, e.deployment_namespace, e.deployment_boot_id, e.deployment_epoch
        FROM scheduler_occurrences o JOIN scheduler_occurrence_executions e ON e.occurrence_id = o.id
        WHERE o.origin = 'manual' AND o.command = 'app:transcode:cache-sweep'
        SQL);
    if ($occurrence === false || $occurrence['dispatched_at'] === null || $occurrence['returned_at'] === null
        || $occurrence['deployment_namespace'] !== $namespace || $occurrence['deployment_boot_id'] !== $bootId
        || (int) $occurrence['deployment_epoch'] !== 1
        || (int) $db->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions') !== 1
        || (int) $db->fetchOne('SELECT run_count FROM scheduled_jobs WHERE command = :command', ['command' => 'app:transcode:cache-sweep']) !== 1
        || $db->fetchOne("SELECT 1 FROM scheduled_jobs WHERE command = 'app:transcode:cache-sweep' AND last_error IS NULL AND last_result LIKE '%DRY RUN%'") === false) {
        $job = $db->fetchAssociative("SELECT run_count, last_error IS NULL AS no_error, last_result LIKE '%DRY RUN%' AS dry_run FROM scheduled_jobs WHERE command = 'app:transcode:cache-sweep'");
        throw new RuntimeException('Real scheduler/consumer must finish the manual console occurrence exactly once under its admitted lease: ' . json_encode(['receipt' => $occurrence, 'job' => $job], JSON_THROW_ON_ERROR));
    }
    $redis = new Redis();
    $redis->connect('redis', 6379, 1.0);
    $redis->auth(['default', 'test-only']);
    $pending = $redis->xPending('scheduler_occurrences', 'baander');
    if (!is_array($pending) || ($pending[0] ?? null) !== 0 || $redis->xLen('scheduler_occurrences') !== 0
        || $redis->zCard('scheduler_occurrences__queue') !== 0 || $redis->xLen('failed_messages') !== 0) {
        throw new RuntimeException('Scheduler occurrence must be acknowledged without retries or dead letters.');
    }
    if ($mode === 'replay-scheduler') {
        // A returned occurrence is never claimed again by the relay. Exercise the
        // consumer's retained deduplication guard with an actual duplicate delivery.
        $transport = new Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport(
            Symfony\Component\Messenger\Bridge\Redis\Transport\Connection::fromDsn('redis://default:test-only@redis:6379/scheduler_occurrences/baander'),
            new App\Shared\Infrastructure\Messenger\JsonTransportSerializer(MessageCodecFactory::create()),
        );
        $transport->send(new Symfony\Component\Messenger\Envelope(new App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand(App\Shared\Domain\Model\Uuid::fromString($occurrence['id']))));
        echo "Replayed duplicate scheduler envelope after confirmed execution.\n";
    } else {
        echo "Independent observer verified one scheduler execution and clean dedicated-stream acknowledgment.\n";
    }
    exit(0);
}
if ($mode === 'seed-outbox') {
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3) . '/.env');
    $kernel = new App\Kernel('prod', false);
    $kernel->boot();
    $em = $kernel->getContainer()->get('doctrine')->getManager();
    $user = new App\Auth\Infrastructure\Doctrine\Entity\UserEntity(new App\Shared\Domain\Model\PublicId(), 'Worker Command User', 'worker-command@baander.app', 'unused-password', '');
    $em->persist($user); // Unverified: CreateNotificationHandler does not enqueue email.
    foreach (['email', 'push', 'webhook'] as $channel) {
        $preference = new App\Notification\Infrastructure\Doctrine\Entity\NotificationPreferenceEntity(App\Shared\Domain\Model\Uuid::v7());
        $preference->setUser($user);
        $preference->setCategory('security');
        $preference->setChannel($channel);
        $preference->setEnabled(false);
        $em->persist($preference);
    }
    $em->flush();
    $kernel->getContainer()->get('event_dispatcher')->dispatch(new App\Auth\Domain\Event\UserRegistered($user->getId(), $user->getPublicId(), App\Shared\Domain\Model\Email::fromString($user->getEmail()), $user->getName()));
    if ((int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox') !== 1 || (int) $db->fetchOne('SELECT count(*) FROM notifications') !== 0 || (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox_delivery') !== 0 || (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox_receipt') !== 0) {
        throw new RuntimeException('Real capture must commit exactly one pending event without projecting it.');
    }
    $kernel->shutdown();
    echo "Real UserRegistered event captured; outbound preferences disabled.\n";
    exit(0);
}
if ($mode === 'verify-outbox' || $mode === 'replay-outbox') {
    $event = $db->fetchAssociative("SELECT id, relayed_at, attempts, dead_lettered_at FROM domain_event_outbox WHERE event_name = 'user.registered' AND payload->>'email' = :email", ['email' => 'worker-command@baander.app']);
    if ($event === false || $event['relayed_at'] === null || (int) $event['attempts'] !== 0 || $event['dead_lettered_at'] !== null
        || (int) $db->fetchOne("SELECT count(*) FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.email = :email AND n.event_type = 'user.registered'", ['email' => 'worker-command@baander.app']) !== 1
        || (int) $db->fetchOne("SELECT count(*) FROM domain_event_outbox_receipt WHERE outbox_id = :id AND consumer = 'notifications.v1'", ['id' => $event['id']]) !== 1
        || (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox_delivery') !== 2
        || (int) $db->fetchOne("SELECT count(*) FROM domain_event_outbox_delivery d JOIN notifications n ON n.public_id = d.notification_id JOIN users u ON u.id = n.user_id WHERE u.email = :email AND d.channel IN ('push', 'webhook') AND d.relayed_at IS NOT NULL AND d.attempts = 0 AND d.dead_lettered_at IS NULL", ['email' => 'worker-command@baander.app']) !== 2) {
        throw new RuntimeException('Real command has not committed the expected projection, receipt and two durable Redis handoffs.');
    }
    $redis = new Redis();
    $redis->connect('redis', 6379, 1.0);
    $redis->auth(['default', 'test-only']);
    $pending = $redis->xPending('messages', 'baander');
    if (!is_array($pending) || ($pending[0] ?? null) !== 0 || $redis->xLen('messages') !== 0
        || $redis->xLen('failed_messages') !== 0 || $redis->zCard('messages__queue') !== 0
        || $redis->zCard('failed_messages__queue') !== 0) {
        throw new RuntimeException('Confirmed handoffs have not both been consumed and acknowledged without retries/dead letters.');
    }
    if ($mode === 'replay-outbox') {
        $db->executeStatement('UPDATE domain_event_outbox SET relayed_at = NULL WHERE id = :id', ['id' => $event['id']]);
        echo "Simulated lost event acknowledgment after committed effects.\n";
    } else {
        echo "Independent observer verified one projection/receipt, two handoffs and clean async acknowledgments.\n";
    }
    exit(0);
}
$row = $db->fetchAssociative('SELECT owner_boot_id, state, epoch FROM worker_deployment_leases WHERE namespace = :namespace', ['namespace' => $namespace]);
if ($row === false || $row['owner_boot_id'] !== $bootId || $row['state'] !== 'active' || (int) $row['epoch'] !== 1) {
    throw new RuntimeException('Expected the original active, epoch-one deployment reservation.');
}
if ($mode === 'expire') {
    $changed = $db->executeStatement("UPDATE worker_deployment_leases SET expires_at = clock_timestamp() - INTERVAL '1 second' WHERE namespace = :namespace AND owner_boot_id = :boot AND state = 'active' AND epoch = 1", ['namespace' => $namespace, 'boot' => $bootId]);
    if ($changed !== 1) {
        throw new RuntimeException('Could not expire the exact disposable deployment reservation.');
    }
    echo "Expired the exact original reservation; ownership remains active.\n";
    exit(0);
}
if ($mode === 'reserved') {
    echo "Original deployment lease remains reserved.\n";
    exit(0);
}

$roles = [];
foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
    $pid = (int) basename(dirname($path));
    $command = @file_get_contents($path);
    if ($pid === 1 || $pid === getmypid() || $command === false) {
        continue;
    }
    $arguments = explode("\0", rtrim($command, "\0"));
    $role = in_array('messenger:consume', $arguments, true) ? 'consumer'
        : (in_array('app:outbox:consume', $arguments, true) ? 'relay'
            : (in_array(dirname(__DIR__, 3) . '/bin/worker-scheduler.php', $arguments, true) ? 'scheduler' : null));
    if ($role === null) {
        continue;
    }
    if ($role === 'consumer' && (!in_array('async', $arguments, true) || !in_array('scheduler', $arguments, true))) {
        throw new RuntimeException('Consumer must receive both durable async and scheduler transports.');
    }
    $status = @file_get_contents(dirname($path) . '/status');
    if ($status === false || preg_match('/^PPid:\s+(\d+)$/m', $status, $matches) !== 1 || (int) $matches[1] !== 1) {
        throw new RuntimeException('Production role is not a direct child of PID 1.');
    }
    if (isset($roles[$role])) {
        throw new RuntimeException('Duplicate production worker role.');
    }
    $environment = @file_get_contents(dirname($path) . '/environ');
    if ($environment === false) {
        throw new RuntimeException('Could not inspect production role launch identity.');
    }
    $identity = [];
    $expectedIdentity = [
        'BAANDER_WORKER_NAMESPACE' => $namespace,
        'BAANDER_WORKER_BOOT_ID' => $bootId,
        'BAANDER_WORKER_LEASE_EPOCH' => (string) $row['epoch'],
        'BAANDER_WORKER_ID' => $role,
        'BAANDER_WORKER_GENERATION' => '1',
        'MESSENGER_CONSUMER_NAME' => 'worker-' . substr(hash('sha256', $namespace), 0, 16) . '-' . $bootId . '-' . $role . '-1',
    ];
    foreach (explode("\0", $environment) as $entry) {
        $pair = explode('=', $entry, 2);
        if (count($pair) === 2 && array_key_exists($pair[0], $expectedIdentity)) {
            if (array_key_exists($pair[0], $identity)) {
                throw new RuntimeException('Duplicate production role launch identity field.');
            }
            $identity[$pair[0]] = $pair[1];
        }
    }
    foreach ($expectedIdentity as $name => $expectedValue) {
        if (($identity[$name] ?? null) !== $expectedValue) {
            throw new RuntimeException('Production role has incorrect launch identity field: ' . $name);
        }
    }
    $roles[$role] = $pid;
}
if (count($roles) !== 3 || !isset($roles['consumer'], $roles['relay'], $roles['scheduler'])) {
    throw new RuntimeException('All three production roles have not started.');
}
$redis = new Redis();
$redis->connect('redis', 6379, 1.0);
$redis->auth(['default', 'test-only']);
$consumers = $redis->xInfo('CONSUMERS', 'messages', 'baander');
$expected = 'worker-' . substr(hash('sha256', $namespace), 0, 16) . '-' . $bootId . '-consumer-1';
if (!is_array($consumers) || !in_array($expected, array_column($consumers, 'name'), true)) {
    throw new RuntimeException('Expected Redis consumer ' . $expected . '; observed ' . json_encode($consumers, JSON_THROW_ON_ERROR));
}
if (in_array($mode, ['kill-relay-claimed', 'kill-relay-projecting', 'kill-relay-after-send'], true)
    && !posix_kill($roles['relay'], SIGKILL)) {
    throw new RuntimeException('Could not kill the direct relay child at its verified crash boundary.');
}
if (in_array($mode, ['kill-consumer', 'kill-consumer-before-ack'], true)
    && !posix_kill($roles['consumer'], SIGKILL)) {
    throw new RuntimeException('Could not kill the disposable consumer fixture.');
}
if ($mode === 'kill-scheduler' && !posix_kill($roles['scheduler'], SIGKILL)) {
    throw new RuntimeException('Could not kill the disposable scheduler fixture.');
}
echo json_encode(['namespace' => $namespace, 'bootId' => $bootId, ...$roles], JSON_THROW_ON_ERROR) . "\n";
