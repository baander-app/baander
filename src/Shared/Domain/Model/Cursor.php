<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model;

final readonly class Cursor
{
    /**
     * @param array<string, mixed> $values Associative: sort column name => value, plus `id` key for tiebreaker
     *                                    and an optional `binding` key naming the ordering the cursor belongs to
     */
    private function __construct(
        private CursorDirection $direction,
        private array $values,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function create(CursorDirection $direction, array $values): self
    {
        return new self($direction, $values);
    }

    public function getDirection(): CursorDirection
    {
        return $this->direction;
    }

    /**
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
