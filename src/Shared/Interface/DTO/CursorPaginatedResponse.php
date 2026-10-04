<?php

declare(strict_types=1);

namespace App\Shared\Interface\DTO;

use App\Shared\Domain\Model\CursorPage;
use Nelmio\ApiDocBundle\Attribute\Ignore;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CursorPaginatedResponse',
    required: ['data', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(nullable: true)),
        new OA\Property(property: 'meta', required: ['next_cursor', 'prev_cursor', 'has_next_page', 'has_previous_page', 'total', 'per_page', 'stale_cursor'], properties: [
            new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
            new OA\Property(property: 'prev_cursor', type: 'string', nullable: true),
            new OA\Property(property: 'has_next_page', type: 'boolean'),
            new OA\Property(property: 'has_previous_page', type: 'boolean'),
            new OA\Property(property: 'total', type: 'integer'),
            new OA\Property(property: 'per_page', type: 'integer'),
            new OA\Property(property: 'stale_cursor', type: 'boolean'),
        ], type: 'object'),
    ],
)]
final readonly class CursorPaginatedResponse
{
    /**
     * @param array<int, mixed> $data
     */
    public function __construct(
        // Document the toArray() envelope rather than these constructor fields.
        #[Ignore]
        public array $data,
        #[Ignore]
        public ?string $nextCursor,
        #[Ignore]
        public ?string $prevCursor,
        #[Ignore]
        public bool $hasNextPage,
        #[Ignore]
        public bool $hasPreviousPage,
        #[Ignore]
        public int $total,
        #[Ignore]
        public bool $staleCursor,
        #[Ignore]
        public int $perPage,
    ) {
    }

    /** @param array<int, mixed> $data */
    public static function fromPage(CursorPage $page, array $data): self
    {
        return new self(
            data: $data,
            nextCursor: $page->getNextCursor(),
            prevCursor: $page->getPrevCursor(),
            hasNextPage: $page->hasNextPage(),
            hasPreviousPage: $page->hasPreviousPage(),
            total: $page->getTotal(),
            staleCursor: $page->isStaleCursor(),
            perPage: $page->getPerPage(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'meta' => [
                'next_cursor' => $this->nextCursor,
                'prev_cursor' => $this->prevCursor,
                'has_next_page' => $this->hasNextPage,
                'has_previous_page' => $this->hasPreviousPage,
                'total' => $this->total,
                'per_page' => $this->perPage,
                'stale_cursor' => $this->staleCursor,
            ],
        ];
    }
}
