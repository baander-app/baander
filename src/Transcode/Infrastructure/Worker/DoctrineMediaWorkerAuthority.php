<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Worker;

use App\Shared\Infrastructure\Doctrine\DedicatedConnectionFactory;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class DoctrineMediaWorkerAuthority implements MediaWorkerAuthorityInterface
{
    public function __construct(private Connection $connection, private MediaWorkerIdentity $identity) {}

    public static function fromDsn(#[\SensitiveParameter] string $databaseUrl): self
    {
        return new self(DedicatedConnectionFactory::fromUrl($databaseUrl), MediaWorkerEnvironment::identity());
    }

    public function assertActive(): void
    {
        if (!$this->connection->isAutoCommit() || $this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Media authority requires an idle dedicated autocommit connection.');
        }
        try {
            $this->connection->beginTransaction();
            $this->connection->executeQuery("SELECT set_config('statement_timeout', '1000ms', true), set_config('lock_timeout', '250ms', true)")->free();
            $active = $this->connection->fetchOne(<<<'SQL'
                SELECT 1 FROM worker_deployment_leases
                WHERE namespace = :namespace AND owner_boot_id = :boot AND epoch = :epoch
                    AND state = 'active' AND expires_at > clock_timestamp()
                SQL, ['namespace' => $this->identity->namespace, 'boot' => $this->identity->bootId, 'epoch' => $this->identity->leaseEpoch]) !== false;
            if (!$active) {
                throw new \RuntimeException('Media requires active deployment authority.');
            }
            $this->connection->commit();
        } catch (\Throwable $error) {
            try {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
            } catch (\Throwable) {
                // The admission failure remains authoritative when rollback also fails.
            }
            try {
                $this->connection->close();
            } catch (\Throwable) {
                // A failed connection cannot authorize further work.
            }
            throw $error;
        }
    }
}
