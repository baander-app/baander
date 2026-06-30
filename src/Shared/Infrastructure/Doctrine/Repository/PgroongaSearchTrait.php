<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\SearchOptions;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\ORM\QueryBuilder as DoctrineQueryBuilder;

trait PgroongaSearchTrait
{
    /**
     * Build a scored search query using native SQL with pgroonga_score.
     *
     * Returns an array with keys: 'entities' (Doctrine entity objects), 'total' (int), 'highestScore' (float).
     * The calling repository must map entities to domain models via its own entityToDomain().
     *
     * @return array{entities: list<object>, total: int, highestScore: float}
     */
    private function buildScoredQuery(
        SearchOptions $options,
        EntityManagerInterface $em,
        string $entityClass,
        string $tableName,
        string $searchColumn,
    ): array {
        if (!$options->hasQuery()) {
            return ['entities' => [], 'total' => 0, 'highestScore' => 0.0];
        }

        if (!preg_match('/^[a-z_]+$/', $tableName)) {
            throw new \InvalidArgumentException(sprintf('Invalid table name: %s', $tableName));
        }

        if (!preg_match('/^[a-z_.]+$/', $searchColumn)) {
            throw new \InvalidArgumentException(sprintf('Invalid search column: %s', $searchColumn));
        }

        $escapedQuery = $em->getConnection()->quote($options->getQuery() . '*');

        // COUNT query
        $countSql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s &@~ %s',
            $tableName,
            $searchColumn,
            $escapedQuery,
        );
        $total = (int) $em->getConnection()->executeQuery($countSql)->fetchOne();

        if ($total === 0) {
            return ['entities' => [], 'total' => 0, 'highestScore' => 0.0];
        }

        // Scored SELECT query
        $rsm = new ResultSetMappingBuilder($em);
        $rsm->addRootEntityFromClassMetadata($entityClass, 's');
        $rsm->addScalarResult('score', 'score', 'float');

        $sql = sprintf(
            'SELECT s.*, pgroonga_score(s.tableoid, s.ctid) AS score FROM %s s WHERE %s &@~ %s ORDER BY score DESC LIMIT %d OFFSET %d',
            $tableName,
            $searchColumn,
            $escapedQuery,
            $options->getLimit(),
            $options->getOffset(),
        );

        $stmt = $em->createNativeQuery($sql, $rsm);
        $rows = $stmt->getResult();

        // Doctrine returns each row as: [0 => entity, 'score' => float]
        $entities = [];
        $highestScore = 0.0;
        foreach ($rows as $row) {
            if (is_array($row)) {
                if (is_object($row[0])) {
                    $entities[] = $row[0];
                }
                if (isset($row['score'])) {
                    $highestScore = max($highestScore, (float) $row['score']);
                }
            }
        }

        return ['entities' => $entities, 'total' => $total, 'highestScore' => $highestScore];
    }

    /**
     * Build a filter-only query using DQL with pgroonga_match().
     *
     * When a query is provided, applies PGroonga text matching. When empty,
     * returns the full unfiltered set (used for cursor-paginated listing
     * where empty query = show all items).
     *
     * @param DoctrineQueryBuilder $qb QueryBuilder with base alias already set
     * @param callable(DoctrineQueryBuilder, list<array{field: string, operator: string, value: mixed}>): void $applyFilters Repository-specific filter application callback
     */
    private function buildFilterQuery(
        SearchOptions $options,
        DoctrineQueryBuilder $qb,
        string $dqlColumn,
        callable $applyFilters,
    ): DoctrineQueryBuilder {
        if ($options->hasQuery()) {
            $qb->where(sprintf("pgroonga_match(%s, :search_query) = true", $dqlColumn))
                ->setParameter('search_query', $options->getQuery());
        }
        // No WHERE clause when query is empty → cursor-paginated listing returns all items

        $applyFilters($qb, $options->getFilters());

        return $qb;
    }
}
