<?php

declare(strict_types=1);

namespace App\Shared\Interface\Request;

/**
 * A numbered page of `limit` rows. Pages count from 1, and the page's offset is
 * (page - 1) × limit.
 */
final readonly class PageNumberPagination
{
    public int $offset;

    /** @throws \InvalidArgumentException when the page lies outside 1 to maximumPage($limit) */
    public function __construct(public int $page, public int $limit)
    {
        if ($limit < 1 || $page < 1 || $page > self::maximumPage($limit)) {
            throw new \InvalidArgumentException(sprintf('Page %d of %d rows lies outside a 64-bit offset.', $page, $limit));
        }

        $this->offset = ($page - 1) * $limit;
    }

    /**
     * The highest page for which page × limit stays within PHP_INT_MAX, so neither the
     * offset nor the end of the page overflows PHP's integer or PostgreSQL's bigint.
     */
    public static function maximumPage(int $limit): int
    {
        return intdiv(PHP_INT_MAX, $limit);
    }

    /** The last page that holds rows; an empty list has one empty page. */
    public function lastPage(int $total): int
    {
        return max(1, (int) ceil($total / $this->limit));
    }
}
