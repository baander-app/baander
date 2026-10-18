<?php

declare(strict_types=1);

use App\Auth\Domain\Event\UserRegistered;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerManualOccurrenceRecorder;
use App\Shared\Domain\Event\Outbox\OutboxRepository;
use App\Shared\Domain\Event\Outbox\OutboxSubscriber;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\DedicatedConnectionFactory;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @param array<string, mixed> $receipt */
function writeLifecycleReceipt(string $path, array $receipt): void
{
    $temporary = tempnam(dirname($path), '.lifecycle-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create lifecycle receipt temporary file.');
    }
    try {
        $json = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) {
            throw new RuntimeException('Cannot atomically publish lifecycle receipt.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

/** @return array<string, mixed> */
function lifecycleInput(): array
{
    $input = stream_get_contents(STDIN, 65537);
    if ($input === false || strlen($input) > 65536) {
        throw new RuntimeException('Lifecycle input exceeds its supported bound.');
    }
    $decoded = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Lifecycle input must be a JSON object.');
    }
    return $decoded;
}

/** @param array<string, mixed> $owner */
function lifecycleIdentity(array $owner): DeploymentLease
{
    if (!is_string($owner['namespace'] ?? null) || !is_string($owner['bootId'] ?? null)
        || !is_int($owner['epoch'] ?? null)) {
        throw new RuntimeException('Lifecycle owner requires namespace, bootId and integer epoch.');
    }
    return new DeploymentLease($owner['namespace'], $owner['bootId'], $owner['epoch']);
}

/** @return array<string, mixed> */
function lifecycleLease(Connection $db, DeploymentLease $owner): array
{
    $row = $db->fetchAssociative(<<<'SQL'
        SELECT namespace, owner_boot_id, epoch, state, expires_at::text,
            clock_timestamp()::text AS database_now,
            CASE WHEN expires_at > clock_timestamp() THEN 1 ELSE 0 END AS fresh
        FROM worker_deployment_leases WHERE namespace = :namespace
        SQL, ['namespace' => $owner->namespace]);
    if ($row === false || $row['owner_boot_id'] !== $owner->bootId
        || (int) $row['epoch'] !== $owner->epoch || $row['state'] !== 'active') {
        throw new RuntimeException('Expected exact active deployment reservation.');
    }
    $row['epoch'] = (int) $row['epoch'];
    $row['fresh'] = (int) $row['fresh'] === 1;
    return $row;
}

$arguments = $_SERVER['argv'] ?? [];
[$script, $action, $namespace, $bootId, $epoch, $receiptPath] = $arguments + [null, null, null, null, null, null];
if (!is_string($receiptPath) || $receiptPath === '' || str_contains($receiptPath, "\0")) {
    throw new RuntimeException('Lifecycle observer requires a receipt file path.');
}
$receipt = ['schemaVersion' => 1, 'action' => $action, 'success' => false];
$db = null;
try {
    if (count($arguments) !== 6 || !in_array($action, ['seed', 'pending', 'completed', 'lease', 'stale-lease'], true)
        || !is_string($epoch) || preg_match('/\A[1-9][0-9]{0,8}\z/D', $epoch) !== 1) {
        throw new RuntimeException('Invalid lifecycle observer arguments.');
    }
    $owner = lifecycleIdentity(['namespace' => $namespace, 'bootId' => $bootId, 'epoch' => (int) $epoch]);
    $receipt['owner'] = ['namespace' => $owner->namespace, 'bootId' => $owner->bootId, 'epoch' => $owner->epoch];
    $url = getenv('DATABASE_URL');
    if (!is_string($url) || $url === '') {
        throw new RuntimeException('Disposable DATABASE_URL is required.');
    }
    CustomTypesRegistrar::register();
    $db = DedicatedConnectionFactory::fromUrl($url);
    $db->executeStatement("SET statement_timeout = '2000ms'");
    $db->executeStatement("SET lock_timeout = '250ms'");
    $receipt['lease'] = lifecycleLease($db, $owner);

    if ($action === 'stale-lease') {
        $input = lifecycleInput();
        $predecessor = lifecycleIdentity($input['predecessor'] ?? []);
        $successor = lifecycleIdentity($input['successor'] ?? []);
        if ($successor != $owner || $predecessor->namespace !== $owner->namespace
            || $predecessor->bootId === $owner->bootId || $predecessor->epoch >= $owner->epoch) {
            throw new RuntimeException('Stale renewal requires exact predecessor and successor identities.');
        }
        $before = $receipt['lease'];
        $renewed = (new DoctrineDeploymentLease($db))->renew($predecessor, 30);
        $after = lifecycleLease($db, $successor);
        if ($renewed || new DateTimeImmutable($after['expires_at']) < new DateTimeImmutable($before['expires_at'])) {
            throw new RuntimeException('Stale predecessor renewal changed successor authority.');
        }
        $receipt['renewed'] = $renewed;
        $receipt['before'] = $before;
        $receipt['lease'] = $after;
    } elseif ($action === 'seed') {
        $userId = Uuid::v7();
        $publicId = new PublicId();
        $email = 'worker-lifecycle-' . bin2hex(random_bytes(12)) . '@baander.app';
        $jobId = Uuid::v7();
        $occurrenceId = Uuid::v7();
        $db->transactional(function (Connection $db) use ($userId, $publicId, $email): void {
            $db->executeStatement(<<<'SQL'
                INSERT INTO users (id, public_id, name, email, password, created_at, updated_at)
                VALUES (:id, :publicId, 'Worker Lifecycle User', :email, 'unused-password', clock_timestamp(), clock_timestamp())
                SQL, ['id' => $userId->toString(), 'publicId' => $publicId->toString(), 'email' => $email]);
            foreach (['email', 'push', 'webhook'] as $channel) {
                $db->executeStatement(<<<'SQL'
                    INSERT INTO notification_preferences (id, user_id, category, channel, enabled)
                    VALUES (:id, :user, 'security', :channel, false)
                    SQL, ['id' => Uuid::v7()->toString(), 'user' => $userId->toString(), 'channel' => $channel]);
            }
            (new OutboxSubscriber(new OutboxRepository($db), new NullLogger()))(
                new UserRegistered($userId, $publicId, Email::fromString($email), 'Worker Lifecycle User'),
            );
        });
        $events = $db->fetchFirstColumn(<<<'SQL'
            SELECT id FROM domain_event_outbox
            WHERE event_name = 'user.registered' AND payload->>'user_id' = :user AND payload->>'email' = :email
            SQL, ['user' => $userId->toString(), 'email' => $email]);
        if (count($events) !== 1) {
            throw new RuntimeException('Real event producer must commit exactly one identified outbox event.');
        }
        $db->executeStatement(<<<'SQL'
            INSERT INTO scheduled_jobs (id, public_id, revision, name, expression, job_type, command, status, parameters, created_at, updated_at, run_count)
            VALUES (:id, :publicId, :revision, :name, '* * * * *', 'console', 'app:transcode:cache-sweep', 'paused', '{"dry-run":true}', clock_timestamp(), clock_timestamp(), 0)
            SQL, ['id' => $jobId->toString(), 'publicId' => (new PublicId())->toString(),
                'revision' => Uuid::v7()->toString(), 'name' => 'Worker lifecycle ' . $email]);
        if ((new DoctrineSchedulerManualOccurrenceRecorder($db))->record($jobId, $occurrenceId) === null) {
            throw new RuntimeException('Real manual occurrence recorder did not retain the requested work.');
        }
        $receipt['work'] = ['email' => $email, 'userId' => $userId->toString(), 'outboxId' => (string) $events[0],
            'jobId' => $jobId->toString(), 'occurrenceId' => $occurrenceId->toString()];
    } elseif ($action === 'pending' || $action === 'completed') {
        $input = lifecycleInput();
        $work = $input['work'] ?? null;
        if (($input['schemaVersion'] ?? null) !== 1 || ($input['action'] ?? null) !== 'seed'
            || ($input['success'] ?? null) !== true || !is_array($work)) {
            throw new RuntimeException('Work observation requires a successful seed receipt.');
        }
        foreach (['email', 'userId', 'outboxId', 'jobId', 'occurrenceId'] as $key) {
            if (!is_string($work[$key] ?? null) || $work[$key] === '') {
                throw new RuntimeException('Seed receipt is missing an exact work identity.');
            }
        }
        $seedOwner = lifecycleIdentity($input['owner'] ?? []);
        if ($seedOwner->namespace !== $owner->namespace || $seedOwner->epoch > $owner->epoch
            || ($action === 'pending' && $seedOwner != $owner)) {
            throw new RuntimeException('Seed receipt does not belong to the observed deployment lineage.');
        }
        $receipt['work'] = $work;
        $event = $db->fetchAssociative(<<<'SQL'
            SELECT o.id, o.relayed_at, o.dead_lettered_at, o.attempts
            FROM domain_event_outbox o JOIN users u ON u.id = :user
            WHERE o.id = :outbox AND o.event_name = 'user.registered'
                AND o.payload->>'user_id' = :user AND o.payload->>'email' = :email AND u.email = :email
            SQL, ['user' => $work['userId'], 'outbox' => $work['outboxId'], 'email' => $work['email']]);
        $notifications = $db->fetchAllAssociative(<<<'SQL'
            SELECT public_id FROM notifications WHERE user_id = :user AND event_type = 'user.registered'
            SQL, ['user' => $work['userId']]);
        $deliveries = $db->fetchAllAssociative(<<<'SQL'
            SELECT d.id, d.channel, d.relayed_at, d.dead_lettered_at, d.attempts
            FROM domain_event_outbox_delivery d JOIN notifications n ON n.public_id = d.notification_id
            WHERE n.user_id = :user AND n.event_type = 'user.registered' ORDER BY d.channel, d.id
            SQL, ['user' => $work['userId']]);
        $projectionReceipts = (int) $db->fetchOne(<<<'SQL'
            SELECT count(*) FROM domain_event_outbox_receipt WHERE outbox_id = :id AND consumer = 'notifications.v1'
            SQL, ['id' => $work['outboxId']]);
        $occurrence = $db->fetchAssociative(<<<'SQL'
            SELECT o.id, o.dispatch_token, o.dispatched_at, j.run_count, j.last_result, j.last_error
            FROM scheduler_occurrences o JOIN scheduled_jobs j ON j.id = o.job_id
            WHERE o.id = :occurrence AND o.job_id = :job AND o.origin = 'manual'
                AND o.command = 'app:transcode:cache-sweep' AND j.status = 'paused'
            SQL, ['occurrence' => $work['occurrenceId'], 'job' => $work['jobId']]);
        $executions = $db->fetchAllAssociative(<<<'SQL'
            SELECT attempt_id, returned_at, deployment_namespace, deployment_boot_id, deployment_epoch
            FROM scheduler_occurrence_executions WHERE occurrence_id = :occurrence AND job_id = :job
            SQL, ['occurrence' => $work['occurrenceId'], 'job' => $work['jobId']]);
        $relayed = array_filter($deliveries, static fn (array $row): bool => $row['relayed_at'] !== null && $row['dead_lettered_at'] === null);
        $receipt['observed'] = ['event' => $event, 'notifications' => $notifications, 'projectionReceipts' => $projectionReceipts,
            'deliveries' => $deliveries, 'occurrence' => $occurrence, 'executions' => $executions];
        if ($event === false || $event['dead_lettered_at'] !== null || $occurrence === false) {
            throw new RuntimeException('Exact retained work is absent or dead-lettered.');
        }
        if ($action === 'pending') {
            if (count($relayed) >= 2 || $executions !== [] || (int) $occurrence['run_count'] !== 0) {
                throw new RuntimeException('Expected an unfinished outbox handoff and an unexecuted manual occurrence.');
            }
        } else {
            $execution = $executions[0] ?? null;
            if ($event['relayed_at'] === null || count($notifications) !== 1 || $projectionReceipts !== 1
                || count($deliveries) !== 2 || count($relayed) !== 2 || array_column($deliveries, 'channel') !== ['push', 'webhook']
                || count($executions) !== 1 || $execution['returned_at'] === null
                || $execution['deployment_namespace'] !== $owner->namespace || $execution['deployment_boot_id'] !== $owner->bootId
                || (int) $execution['deployment_epoch'] !== $owner->epoch || $occurrence['dispatch_token'] === null
                || (int) $occurrence['run_count'] !== 1 || $occurrence['last_error'] !== null
                || !str_contains($occurrence['last_result'] ?? '', 'DRY RUN')) {
                throw new RuntimeException('Exact retained work has not completed once under successor authority.');
            }
            $redis = new Redis();
            $redis->connect('redis', 6379, 1.0, null, 0, 1.0);
            $redis->auth(['default', 'test-only']);
            try {
                foreach (['messages', 'scheduler_occurrences'] as $stream) {
                    $pending = $redis->xPending($stream, 'baander');
                    $length = $redis->xLen($stream);
                    $delayed = $redis->zCard($stream . '__queue');
                    $receipt['queues'][$stream] = ['pending' => $pending, 'length' => $length, 'delayed' => $delayed];
                    if (!is_array($pending) || ($pending[0] ?? null) !== 0 || $length !== 0 || $delayed !== 0) {
                        throw new RuntimeException('Isolated Redis queues have not fully acknowledged the retained work.');
                    }
                }
            } finally {
                $redis->close();
            }
            $receipt['failedMessages'] = (int) $db->fetchOne('SELECT count(*) FROM failed_messages');
            if ($receipt['failedMessages'] !== 0) {
                throw new RuntimeException('Isolated work produced failed transport messages.');
            }
        }
    }
    $receipt['success'] = true;
    writeLifecycleReceipt($receiptPath, $receipt);
} catch (Throwable $error) {
    $receipt['error'] = ['class' => $error::class, 'message' => $error->getMessage()];
    writeLifecycleReceipt($receiptPath, $receipt);
    fwrite(STDERR, 'Lifecycle observer failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $db?->close();
}
