<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Support\FinalSchemaBootstrap;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Infrastructure\Doctrine\TranscodeRuntimeState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

trait MediaRuntimePersistenceHarness
{
    private Connection $first;
    private Connection $second;
    private string $schema;
    private Uuid $jobId;
    private Uuid $sessionId;
    private Uuid $videoId;
    private Uuid $userId;
    private TranscodeRuntimeState $runtime;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->first = DriverManager::getConnection($params);
        $this->second = DriverManager::getConnection($params);
        $this->schema = 'media_runtime_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
            $connection->executeStatement("SET TIME ZONE 'UTC'");
        }
        FinalSchemaBootstrap::bootstrap($this->first);
        $this->runtime = $this->runtimeFor($this->first);
        $this->jobId = Uuid::generate();
        $this->sessionId = Uuid::generate();
        $this->videoId = Uuid::generate();
        $this->userId = Uuid::generate();
        $this->first->executeStatement("INSERT INTO users (id, public_id, name, email, password, created_at, updated_at) VALUES (?, ?, 'Media listener', 'media@baander.app', 'hash', clock_timestamp(), clock_timestamp())", [$this->userId->toString(), (new PublicId())->toString()]);
        $this->first->executeStatement("INSERT INTO videos (id, public_id, path, hash, created_at, updated_at) VALUES (?, ?, '/fixture/video.mp4', 'fixture', clock_timestamp(), clock_timestamp())", [$this->videoId->toString(), (new PublicId())->toString()]);
        $this->first->executeStatement("INSERT INTO transcode_jobs (id, public_id, video_id, quality_tier_name, created_at, updated_at) VALUES (?, ?, ?, '1080p', clock_timestamp(), clock_timestamp())", [$this->jobId->toString(), (new PublicId())->toString(), $this->videoId->toString()]);
    }

    protected function tearDown(): void
    {
        if (isset($this->first)) {
            while ($this->first->isTransactionActive()) {
                $this->first->rollBack();
            }
            $this->second->close();
            $this->first->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->first->close();
        }
    }

    private function runtimeFor(Connection $connection): TranscodeRuntimeState
    {
        $transaction = new class($connection) implements TransactionPortInterface {
            public function __construct(private readonly Connection $connection) {}
            public function run(callable $operation): mixed
            {
                return $this->connection->transactional(static fn (): mixed => $operation());
            }
        };
        return new TranscodeRuntimeState($connection, $transaction);
    }

    private function insertSession(?Uuid $id = null): void
    {
        $this->first->executeStatement('INSERT INTO transcode_sessions (id, public_id, user_id, job_id, video_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, clock_timestamp(), clock_timestamp())', [($id ?? $this->sessionId)->toString(), (new PublicId())->toString(), $this->userId->toString(), $this->jobId->toString(), $this->videoId->toString()]);
    }

    private function admit(): TranscodeRuntimeSnapshot
    {
        return $this->first->transactional(function (): TranscodeRuntimeSnapshot {
            $this->insertSession();
            return $this->runtime->admit($this->jobId, $this->sessionId, 'start', new DesiredTranscodeState(AudioProfile::streamingStereo()));
        });
    }

    private function worker(string $boot = 'a'): MediaWorkerIdentity
    {
        return new MediaWorkerIdentity('baander.app:workers', str_repeat($boot, 32), 'media', 1);
    }
}
