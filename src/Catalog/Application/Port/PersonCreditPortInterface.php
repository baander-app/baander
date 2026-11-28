<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface PersonCreditPortInterface
{
    /**
     * Synchronize all titles in one placement transaction under one sorted lock set.
     *
     * @param list<PersonCreditTargetInput> $targets
     */
    public function syncCredits(array $targets): bool;

    /**
     * The caller owns the transaction. Null means the provider partition was unavailable.
     *
     * @param list<PersonCreditInput>|null $cast
     * @param list<PersonCreditInput>|null $crew
     */
    public function syncMovieCredits(Uuid $movieId, ?array $cast, ?array $crew, bool $forceUpdate = false): bool;

    /**
     * The target kind is either series or episode.
     *
     * @param list<PersonCreditInput>|null $cast
     * @param list<PersonCreditInput>|null $crew
     */
    public function syncTvCredits(string $kind, Uuid $targetId, ?array $cast, ?array $crew, bool $forceUpdate = false): bool;
}
