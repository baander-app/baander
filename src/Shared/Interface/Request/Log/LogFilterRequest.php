<?php

declare(strict_types=1);

namespace App\Shared\Interface\Request\Log;

use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\LogViewer\LogQuery;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'sources' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
            default: [],
        ),
        'search' => new OA\Schema(type: 'string', default: ''),
        'levels' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
            default: [],
        ),
        'channels' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
            default: [],
        ),
        'correlationId' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'since' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'until' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'limit' => new OA\Schema(type: 'integer', default: 200),
        'cursor' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'order' => new OA\Schema(type: 'string', default: 'desc'),
    ],
    component: 'LogFilterRequest',
)]
final readonly class LogFilterRequest
{
    /**
     * @param list<string> $sources
     * @param list<string> $levels
     * @param list<string> $channels
     */
    public function __construct(
        public array $sources = [],
        public string $search = '',
        public array $levels = [],
        public array $channels = [],
        public ?string $correlationId = null,
        public ?string $since = null,
        public ?string $until = null,
        public int $limit = 200,
        public ?string $cursor = null,
        public string $order = 'desc',
    ) {
    }

    public function query(): LogQuery
    {
        return new LogQuery($this->sources, $this->search, $this->levels, $this->channels, $this->correlationId, $this->since, $this->until, $this->limit, $this->cursor, $this->order);
    }

    /** @param array<string, mixed> $parameters */
    public static function fromQuery(array $parameters): self
    {
        $allowed = ['sources', 'search', 'levels', 'channels', 'correlationId', 'since', 'until', 'limit', 'cursor', 'order'];
        foreach ($parameters as $key => $value) {
            if (!in_array($key, $allowed, true) || ($key === 'channels' ? !is_array($value) : !is_string($value))) {
                throw new InvalidInputException('Invalid log query parameter.');
            }
        }
        $limit = $parameters['limit'] ?? '200';
        if (preg_match('/^[0-9]{1,3}$/D', $limit) !== 1) {
            throw new InvalidInputException('Invalid log page limit.');
        }
        $list = static fn (string $key): array => ($parameters[$key] ?? '') === '' ? [] : explode(',', $parameters[$key]);

        return new self($list('sources'), $parameters['search'] ?? '', $list('levels'), $parameters['channels'] ?? [], $parameters['correlationId'] ?? null, $parameters['since'] ?? null, $parameters['until'] ?? null, (int) $limit, $parameters['cursor'] ?? null, $parameters['order'] ?? 'desc');
    }
}
