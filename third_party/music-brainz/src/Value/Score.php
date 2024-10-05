<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A relevance score for search results
 */
class Score implements Value
{
    /**
     * The number
     *
     * @var null|int
     */
    private ?int $number;

    /**
     * Constructs a relevance score.
     *
     * @param null|int $number The number
     */
    public function __construct(int $number = null)
    {
        $this->number = (0 <= $number && $number <= 100)
            ? $number
            : null;
    }

    /**
     * Returns the relevance score as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (null === $this->number)
            ? ''
            : (string) $this->number;
    }

    /**
     * Returns the number.
     *
     * @return null|int
     */
    public function getNumber(): ?int
    {
        return $this->number;
    }
}
