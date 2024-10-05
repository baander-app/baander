<?php

declare(strict_types=1);

namespace MusicBrainz\Filter;

use MusicBrainz\Exception;

/**
 * A page filter
 */
class PageFilter
{
    /**
     * The offset
     *
     * @var int
     */
    private $offset;

    /**
     * The maximum number of items
     *
     * @var int
     */
    private $limit;

    /**
     * Constructs a page filter.
     *
     * @throws Exception
     */
    public function __construct(int $offset = 0, int $limit = 25)
    {
        $this->setOffset($offset)
            ->setLimit($limit);
    }

    /**
     * Returns the offset.
     *
     * @return int
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * Sets the offset.
     *
     * @param int $offset The offset
     *
     * @return self
     */
    public function setOffset(int $offset): self
    {
        $this->offset = $offset;

        return $this;
    }

    /**
     * Returns the limit.
     *
     * @return int
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Sets the limit.
     *
     * @param int $limit The limit
     *
     * @return self
     *
     * @throws Exception
     */
    public function setLimit(int $limit): self
    {
        if ($limit > 100 || $limit < 1) {
            throw new Exception('Limit must be between 1 and 100');
        }

        $this->limit = $limit;

        return $this;
    }
}
