<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pagination;

use App\Shared\Domain\Exception\CursorMismatchException;
use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\CursorDirection;
use Doctrine\ORM\QueryBuilder;

final class CursorPaginator
{
    /**
     * Paginate a Doctrine query using keyset (cursor) pagination.
     *
     * Ascending order is (sort, id). With $nullableSort it is (sort IS NOT NULL,
     * sort, id): rows without a sort key come before every keyed row. Descending
     * order is the exact reverse. Items are returned in the requested order; a
     * Next cursor continues after the last item and a Prev cursor ends before
     * the first one.
     *
     * @param QueryBuilder $qb             QueryBuilder with base filters already applied (not modified in place)
     * @param string       $sortColumn     DQL field expression for sort (e.g. 's.title'); a path expression when $nullableSort
     * @param string       $idColumn       DQL field expression for tiebreaker (e.g. 's.id')
     * @param Cursor|null  $cursor         Cursor from the previous page, or null for the first page
     * @param int          $limit          Number of items per page (must be >= 1)
     * @param callable     $valueExtractor Callable taking an item and returning ['sort' => mixed, 'id' => mixed]
     * @param bool         $withCount      Whether to execute the COUNT query. When false, total will be 0.
     * @param bool         $descending     Whether pages run from the highest key to the lowest
     * @param bool         $nullableSort   Whether the sort expression can be NULL; the extractor then returns null for it
     * @param string|null  $cursorBinding  Identifies the ordering (for example "title:asc"). Issued cursors carry it,
     *                                     and an incoming cursor without the same value is rejected.
     *
     * @throws \InvalidArgumentException if $limit < 1
     * @throws CursorMismatchException  if $cursorBinding is set and the cursor was issued for another ordering
     */
    public function paginate(
        QueryBuilder $qb,
        string $sortColumn,
        string $idColumn,
        ?Cursor $cursor,
        int $limit,
        callable $valueExtractor,
        bool $withCount = true,
        bool $descending = false,
        bool $nullableSort = false,
        ?string $cursorBinding = null,
    ): CursorResult {
        if ($limit < 1) {
            throw new \InvalidArgumentException(sprintf('Limit must be at least 1, got %d.', $limit));
        }
        if ($cursorBinding !== null && $cursor !== null && ($cursor->getValues()['binding'] ?? null) !== $cursorBinding) {
            throw CursorMismatchException::forBinding($cursorBinding);
        }

        $total = $withCount ? $this->executeCount($qb) : 0;

        $backward = $cursor?->getDirection() === CursorDirection::Prev;
        // A backward page is read in reverse and flipped back afterwards.
        $scanDescending = $descending !== $backward;

        $pageQb = clone $qb;
        $position = $cursor !== null ? $this->cursorPosition($cursor, $nullableSort) : null;
        if ($position !== null) {
            $this->applyKeysetCondition($pageQb, $sortColumn, $idColumn, $position['sort'], $position['id'], !$scanDescending, $nullableSort);
        }

        $order = $scanDescending ? 'DESC' : 'ASC';
        $pageQb->resetDQLPart('orderBy');
        if ($nullableSort) {
            $pageQb->addOrderBy(sprintf('CASE WHEN %s IS NULL THEN 0 ELSE 1 END', $sortColumn), $order);
        }
        $pageQb->addOrderBy($sortColumn, $order)
            ->addOrderBy($idColumn, $order)
            ->setMaxResults($limit + 1)
            ->setFirstResult(0);

        /** @var array<mixed> $results */
        $results = $pageQb->getQuery()->getResult();

        $hasMore = count($results) > $limit;
        $items = array_slice($results, 0, $limit);
        if ($backward) {
            $items = array_values(array_reverse($items));
        }

        // A backward page ends where the page that supplied its cursor began.
        $hasNextPage = $backward || $hasMore;
        $hasPreviousPage = $backward ? $hasMore : $cursor !== null;

        $nextCursor = null;
        $prevCursor = null;
        if ($hasNextPage && $items !== []) {
            $nextCursor = $this->cursorAt($items[array_key_last($items)], CursorDirection::Next, $valueExtractor, $cursorBinding);
        }
        if ($hasPreviousPage && $items !== []) {
            $prevCursor = $this->cursorAt($items[array_key_first($items)], CursorDirection::Prev, $valueExtractor, $cursorBinding);
        }

        return new CursorResult(
            items: $items,
            nextCursor: $nextCursor,
            prevCursor: $prevCursor,
            hasNextPage: $hasNextPage,
            hasPreviousPage: $hasPreviousPage,
            total: $total,
            staleCursor: $cursor !== null && $results === [],
            perPage: $limit,
        );
    }

    private function cursorAt(mixed $item, CursorDirection $direction, callable $valueExtractor, ?string $cursorBinding): Cursor
    {
        $values = $valueExtractor($item);
        $position = [
            'sort' => $values['sort'],
            'id' => $values['id'],
        ];
        if ($cursorBinding !== null) {
            $position['binding'] = $cursorBinding;
        }

        return Cursor::create($direction, $position);
    }

    /**
     * The cursor row's key, or null when the cursor carries no usable position.
     *
     * @return array{sort: string|null, id: string}|null
     */
    private function cursorPosition(Cursor $cursor, bool $nullableSort): ?array
    {
        $values = $cursor->getValues();
        if (!array_key_exists('sort', $values) || !isset($values['id'])) {
            return null;
        }
        if ($values['sort'] === null) {
            return $nullableSort ? ['sort' => null, 'id' => (string) $values['id']] : null;
        }

        return ['sort' => (string) $values['sort'], 'id' => (string) $values['id']];
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
     * Keep the rows after the cursor row in ascending order ($greater) or the
     * rows before it, using the order documented on paginate().
     */
    private function applyKeysetCondition(
        QueryBuilder $qb,
        string $sortColumn,
        string $idColumn,
        ?string $sortValue,
        string $idValue,
        bool $greater,
        bool $nullableSort,
    ): void {
        $expr = $qb->expr();
        $idCompare = $greater ? $expr->gt($idColumn, ':cursor_id_val') : $expr->lt($idColumn, ':cursor_id_val');
        $qb->setParameter('cursor_id_val', $idValue);

        if ($sortValue === null) {
            // Keyless rows come first, so only a keyless row can precede this one.
            $qb->andWhere($greater
                ? $expr->orX($expr->isNotNull($sortColumn), $idCompare)
                : $expr->andX($expr->isNull($sortColumn), $idCompare));

            return;
        }

        $condition = $expr->orX(
            $greater ? $expr->gt($sortColumn, ':cursor_sort_val') : $expr->lt($sortColumn, ':cursor_sort_val'),
            $expr->andX($expr->eq($sortColumn, ':cursor_sort_val'), $idCompare),
        );
        if ($nullableSort && !$greater) {
            // A comparison with NULL is never true; keyless rows precede every keyed row.
            $condition = $expr->orX($expr->isNull($sortColumn), $condition);
        }

        $qb->andWhere($condition);
        $qb->setParameter('cursor_sort_val', $sortValue);
    }
}
