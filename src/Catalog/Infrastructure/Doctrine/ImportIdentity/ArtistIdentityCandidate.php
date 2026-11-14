<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\ImportIdentity;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * An artist an import batch may credit: a stored artist the batch read under its locks, or one
 * the batch creates. Only the MusicBrainz ID changes, when an artist without one takes on a file's.
 */
#[Exclude]
final class ArtistIdentityCandidate
{
    /**
     * @param string|null            $mbid      the canonical MusicBrainz artist ID, or null
     * @param DateTimeImmutable|null $createdAt when the stored artist was created; null for an artist the batch creates
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly string $name,
        public ?string $mbid,
        public readonly ?DateTimeImmutable $createdAt,
    ) {
    }
}
