<?php

declare(strict_types=1);

namespace App\Shared\Application\LogViewer;

use App\Shared\Application\Exception\InvalidInputException;

final readonly class LogQuery
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
        if ($limit < 1 || $limit > 500 || !in_array($order, ['asc', 'desc'], true)) {
            throw new InvalidInputException('Log limit must be 1–500 and order must be asc or desc.');
        }
        self::validateSelection($sources);
        self::validateSelection($levels);
        self::validateSelection($channels);
        if ($correlationId !== null && (strlen($correlationId) > 512 || str_contains($correlationId, "\0"))) {
            throw new InvalidInputException('Log correlation ID exceeds its limit.');
        }
        if (strlen($search) > 4096 || strlen($cursor ?? '') > 16384) {
            throw new InvalidInputException('Log search or cursor exceeds its size limit.');
        }
        foreach ([$since, $until] as $date) {
            if ($date !== null && (strlen($date) > 64 || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $date) !== 1 || strtotime($date) === false)) {
                throw new InvalidInputException('Log dates must be RFC 3339 timestamps.');
            }
        }
        if ($since !== null && $until !== null && strtotime($since) > strtotime($until)) {
            throw new InvalidInputException('Log start must precede its end.');
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function validateSelection(array $values): void
    {
        if (!array_is_list($values) || count($values) > 1000) {
            throw new InvalidInputException('Log selections must be lists of at most 1000 values.');
        }
        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 512) {
                throw new InvalidInputException('Invalid log selection.');
            }
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->sources, $this->search, $this->levels, $this->channels, $this->correlationId, $this->since, $this->until, $this->order], JSON_THROW_ON_ERROR));
    }
}
