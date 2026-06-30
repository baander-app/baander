<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pagination;

use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\CursorDirection;
use Doctrine\ORM\QueryBuilder;

final class CursorPaginator
{
    /**
     * Paginate a Doctrine query using keyset (cursor) pagination.
     *
     * @param QueryBuilder $qb            QueryBuilder with base filters already applied (not modified in place)
     * @param string       $sortColumn    DQL field expression for sort (e.g. 's.title')
     * @param string       $idColumn      DQL field expression for tiebreaker (e.g. 's.id')
     * @param Cursor|null  $cursor        Cursor from the previous page, or null for the first page
     * @param int          $limit         Number of items per page (must be >= 1)
     * @param callable     $valueExtractor Callable taking an item and returning ['sort' => mixed, 'id' => mixed]
     * @param bool         $withCount     Whether to execute the COUNT query. When false, total will be 0.
     *
     * @throws \InvalidArgumentException if $limit < 1
     */
    public function paginate(
        QueryBuilder $qb,
        string $sortColumn,
        string $idColumn,
        ?Cursor $cursor,
        int $limit,
        callable $valueExtractor,
        bool $withCount = true,
    ): CursorResult {
        if ($limit < 1) {
            throw new \InvalidArgumentException(sprintf('Limit must be at least 1, got %d.', $limit));
        }

        $direction = $cursor?->getDirection();
        $cursorValues = $cursor?->getValues() ?? [];

        // Step 0: COUNT query (skippable for performance)
        $total = $withCount ? $this->executeCount($qb) : 0;

        // Determine pagination mode
        if ($direction === CursorDirection::Prev) {
            return $this->paginateBackward($qb, $sortColumn, $idColumn, $cursor, $cursorValues, $limit, $valueExtractor, $total);
        }

        return $this->paginateForward($qb, $sortColumn, $idColumn, $cursor, $cursorValues, $limit, $valueExtractor, $total);
    }

    private function paginateForward(
        QueryBuilder $qb,
        string $sortColumn,
        string $idColumn,
        ?Cursor $cursor,
        array $cursorValues,
        int $limit,
        callable $valueExtractor,
        int $total,
    ): CursorResult {
        // Build a fresh QB copy to avoid mutating the caller's query
        $pageQb = clone $qb;

        // Apply keyset WHERE if cursor exists
        if ($cursor !== null && isset($cursorValues['sort'], $cursorValues['id'])) {
            $this->applyKeysetCondition(
                $pageQb,
                $sortColumn,
                $idColumn,
                (string) $cursorValues['sort'],
                (string) $cursorValues['id'],
                'gt',
            );
        }

        // ORDER BY sort ASC, id ASC
        $pageQb->orderBy($sortColumn, 'ASC')
            ->addOrderBy($idColumn, 'ASC')
            ->setMaxResults($limit + 1)
            ->setFirstResult(0);

        /** @var array<mixed> $results */
        $results = $pageQb->getQuery()->getResult();

        $hasNextPage = count($results) > $limit;
        $hasPreviousPage = $cursor !== null;

        // Trim to limit
        $items = array_slice($results, 0, $limit);
        $staleCursor = $cursor !== null && count($results) === 0;

        // Encode cursors
        $nextCursor = null;
        $prevCursor = null;

        if ($hasNextPage && !empty($items)) {
            $lastItem = $items[array_key_last($items)];
            $lastValues = $valueExtractor($lastItem);
            $nextCursor = Cursor::create(CursorDirection::Next, [
                'sort' => $lastValues['sort'],
                'id' => $lastValues['id'],
            ]);
        }

        if ($hasPreviousPage && !empty($items)) {
            $firstItem = $items[array_key_first($items)];
            $firstValues = $valueExtractor($firstItem);
            $prevCursor = Cursor::create(CursorDirection::Prev, [
                'sort' => $firstValues['sort'],
                'id' => $firstValues['id'],
            ]);
        }

        return new CursorResult(
            items: $items,
            nextCursor: $nextCursor,
            prevCursor: $prevCursor,
            hasNextPage: $hasNextPage,
            hasPreviousPage: $hasPreviousPage,
            total: $total,
            staleCursor: $staleCursor,
            perPage: $limit,
        );
    }

    private function paginateBackward(
        QueryBuilder $qb,
        string $sortColumn,
        string $idColumn,
        ?Cursor $cursor,
        array $cursorValues,
        int $limit,
        callable $valueExtractor,
        int $total,
    ): CursorResult {
        $pageQb = clone $qb;

        // Apply reversed keyset WHERE if cursor exists
        if ($cursor !== null && isset($cursorValues['sort'], $cursorValues['id'])) {
            $this->applyKeysetCondition(
                $pageQb,
                $sortColumn,
                $idColumn,
                (string) $cursorValues['sort'],
                (string) $cursorValues['id'],
                'lt',
            );
        }

        // ORDER BY sort DESC, id DESC (reversed for backward seek)
        $pageQb->orderBy($sortColumn, 'DESC')
            ->addOrderBy($idColumn, 'DESC')
            ->setMaxResults($limit + 1)
            ->setFirstResult(0);

        /** @var array<mixed> $results */
        $results = $pageQb->getQuery()->getResult();

        $hasMorePrev = count($results) > $limit;

        // Trim to limit
        $items = array_slice($results, 0, $limit);

        // Reverse back to ASC order
        $items = array_values(array_reverse($items));

        $hasNextPage = $cursor !== null;
        $hasPreviousPage = $hasMorePrev;
        $staleCursor = $cursor !== null && count($results) === 0;

        // Encode cursors from the now-ASC-ordered array
        $nextCursor = null;
        $prevCursor = null;

        if ($hasNextPage && !empty($items)) {
            $lastItem = $items[array_key_last($items)];
            $lastValues = $valueExtractor($lastItem);
            $nextCursor = Cursor::create(CursorDirection::Next, [
                'sort' => $lastValues['sort'],
                'id' => $lastValues['id'],
            ]);
        }

        if ($hasPreviousPage && !empty($items)) {
            $firstItem = $items[array_key_first($items)];
            $firstValues = $valueExtractor($firstItem);
            $prevCursor = Cursor::create(CursorDirection::Prev, [
                'sort' => $firstValues['sort'],
                'id' => $firstValues['id'],
            ]);
        }

        return new CursorResult(
            items: $items,
            nextCursor: $nextCursor,
            prevCursor: $prevCursor,
            hasNextPage: $hasNextPage,
            hasPreviousPage: $hasPreviousPage,
            total: $total,
            staleCursor: $staleCursor,
            perPage: $limit,
        );
    }

    /**
     * Execute a COUNT query on a cloned QueryBuilder.
     */
    private function executeCount(QueryBuilder $qb): int
    {
        $qb = clone $qb;
        $rootAliases = $qb->getRootAliases();
        $rootAlias = $rootAliases[0];

        $qb->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->setFirstResult(0)
            ->setMaxResults(null)
            ->select(sprintf('COUNT(%s)', $rootAlias));

        /** @var int|string $result */
        $result = $qb->getQuery()->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * Apply keyset (seek) condition to the QueryBuilder.
     *
     * Builds: WHERE (sort OP :cursor_sort_val) OR (sort = :cursor_sort_val AND id OP :cursor_id_val)
     *
     * @param 'gt'|'lt' $operator Comparison operator: 'gt' for forward, 'lt' for backward
     */
    private function applyKeysetCondition(
        QueryBuilder $qb,
        string $sortColumn,
        string $idColumn,
        string $sortValue,
        string $idValue,
        string $operator,
    ): void {
        $expr = $qb->expr();

        if ($operator === 'lt') {
            $sortCompare = $expr->lt($sortColumn, ':cursor_sort_val');
            $idCompare = $expr->lt($idColumn, ':cursor_id_val');
        } else {
            $sortCompare = $expr->gt($sortColumn, ':cursor_sort_val');
            $idCompare = $expr->gt($idColumn, ':cursor_id_val');
        }

        $qb->andWhere(
            $expr->orX(
                $sortCompare,
                $expr->andX(
                    $expr->eq($sortColumn, ':cursor_sort_val'),
                    $idCompare,
                ),
            ),
        );

        $qb->setParameter('cursor_sort_val', $sortValue);
        $qb->setParameter('cursor_id_val', $idValue);
    }
}
