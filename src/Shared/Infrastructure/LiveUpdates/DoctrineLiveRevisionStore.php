<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LiveUpdates;

use App\Shared\Application\LiveUpdates\LivePipelineHealth;
use App\Shared\Application\LiveUpdates\LiveRevisionStore;
use Doctrine\DBAL\Connection;

final readonly class DoctrineLiveRevisionStore implements LiveRevisionStore
{
    public function __construct(private Connection $connection) {}

    public function revisions(array $scopes): array
    {
        $this->assertReadable();
        if ($scopes === []) {
            return [];
        }
        $unique = [];
        foreach ($scopes as $scope) {
            $unique[$scope->key()] = $scope;
        }
        $revisions = [];
        foreach (array_chunk($unique, 500) as $batch) {
            $values = [];
            $parameters = [];
            foreach ($batch as $scope) {
                $values[] = '(?, ?, ?)';
                array_push($parameters, $scope->family->value, $scope->kind->value, $scope->id);
            }
            $rows = $this->connection->fetchAllAssociative(
                'SELECT family, scope_kind, scope_id, revision::text AS revision FROM query_scope_revision WHERE (family, scope_kind, scope_id) IN (' . implode(', ', $values) . ') ORDER BY family, scope_kind, scope_id',
                $parameters,
            );
            foreach ($rows as $row) {
                $revisions[$row['family'] . ':' . $row['scope_kind'] . ':' . $row['scope_id']] = $row['revision'];
            }
        }
        ksort($revisions, SORT_STRING);
        return $revisions;
    }

    public function health(): LivePipelineHealth
    {
        $this->assertReadable();
        $age = $this->connection->fetchOne('SELECT CASE WHEN min(created_at) IS NULL THEN NULL ELSE GREATEST(0, floor(extract(epoch FROM (clock_timestamp() - min(created_at))) * 1000))::bigint END FROM query_change_inbox');
        return new LivePipelineHealth($age === null ? null : (int) $age);
    }

    private function assertReadable(): void
    {
        if ($this->connection->getTransactionNestingLevel() !== 0
            && $this->connection->fetchOne("SELECT current_setting('transaction_read_only')") !== 'on'
        ) {
            throw new \LogicException('Live revision observation requires an idle connection or a read-only transaction.');
        }
    }
}
