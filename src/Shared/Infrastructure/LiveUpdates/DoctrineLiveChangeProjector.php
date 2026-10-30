<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LiveUpdates;

use App\Shared\Application\LiveUpdates\LiveChangeProjector;
use Doctrine\DBAL\Connection;

final readonly class DoctrineLiveChangeProjector implements LiveChangeProjector
{
    public function __construct(private Connection $connection) {}

    public function project(): int
    {
        if ($this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Live projection requires an idle connection.');
        }

        try {
            return $this->connection->transactional(function (Connection $connection): int {
                $connection->executeStatement("SET LOCAL transaction_timeout = '250ms'");
                $connection->executeStatement("SET LOCAL statement_timeout = '225ms'");
                if (!$connection->fetchOne("SELECT pg_try_advisory_xact_lock(hashtextextended('baander.query-change-projector', 0))")) {
                    return 0;
                }

                return (int) $connection->fetchOne(<<<'SQL'
                    WITH selected AS MATERIALIZED (
                        SELECT id, family, scope_kind, scope_id
                        FROM query_change_inbox
                        ORDER BY id
                        LIMIT 1000
                    ), advanced AS (
                        INSERT INTO query_scope_revision (family, scope_kind, scope_id, revision)
                        SELECT family, scope_kind, scope_id, 1
                        FROM selected
                        GROUP BY family, scope_kind, scope_id
                        ORDER BY family, scope_kind, scope_id
                        ON CONFLICT (family, scope_kind, scope_id)
                        DO UPDATE SET revision = query_scope_revision.revision + 1
                        RETURNING 1
                    ), deleted AS (
                        DELETE FROM query_change_inbox inbox
                        USING selected
                        WHERE inbox.id = selected.id AND (SELECT count(*) FROM advanced) >= 0
                        RETURNING inbox.id
                    )
                    SELECT count(*) FROM deleted
                    SQL);
            });
        } catch (\Throwable $error) {
            $this->connection->close();
            throw $error;
        }
    }
}
