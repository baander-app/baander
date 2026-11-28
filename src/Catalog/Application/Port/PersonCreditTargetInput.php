<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;

final readonly class PersonCreditTargetInput
{
    /** @var 'movie'|'series'|'episode' */
    public string $kind;

    /**
     * @param list<PersonCreditInput>|null $cast
     * @param list<PersonCreditInput>|null $crew
     */
    public function __construct(
        string $kind,
        public Uuid $targetId,
        public ?array $cast,
        public ?array $crew,
        public bool $forceUpdate = false,
    ) {
        if (!in_array($kind, ['movie', 'series', 'episode'], true)) {
            throw new InvalidArgumentException('Unknown credit target.');
        }
        $this->kind = $kind;
    }
}
