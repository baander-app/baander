<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource;

use App\Shared\Interface\DTO\PaginatedResponse;

abstract class AbstractResource
{
    /**
     * Transform a domain model into a JSON-serializable array.
     *
     * @return array<string, mixed>
     */
    abstract public static function from(mixed $source): array;

    /**
     * Transform a collection of domain models into JSON-serializable arrays.
     *
     * @param iterable<mixed> $items
     *
     * @return array<int, array<string, mixed>>
     */
    final public static function collection(iterable $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[] = static::from($item);
        }

        return $result;
    }

    /**
     * Create a paginated response wrapping a resource collection.
     *
     * @param iterable<mixed> $items
     */
    final public static function paginate(iterable $items, int $currentPage, int $lastPage, int $perPage, int $total): PaginatedResponse
    {
        return new PaginatedResponse(
            data: self::collection($items),
            currentPage: $currentPage,
            lastPage: $lastPage,
            perPage: $perPage,
            total: $total,
        );
    }
}
